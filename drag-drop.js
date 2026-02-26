// ============================================
// DOCUMENT PAGE REORDERING AGENT
// ============================================
// For reordering pages in uploaded documents on the server

class DocumentPageReorderAgent {
    /**
     * Initialize the document page reordering agent
     * @param {Object} config - Configuration object
     * @param {string} config.containerId - ID of the container element
     * @param {string} config.documentId - ID of the document being edited
     * @param {Array} config.pages - Array of page objects [{id, number, url, thumbnail}]
     * @param {string} config.apiEndpoint - Server endpoint for saving new order
     * @param {Function} config.onReorder - Callback when pages are reordered
     * @param {Function} config.onSave - Callback when order is saved to server
     * @param {boolean} config.useHandles - Whether to use drag handles (default: true)
     * @param {string} config.csrfToken - CSRF token for API requests
     */
    constructor(config) {
        // Configuration
        this.containerId = config.containerId;
        this.container = document.getElementById(config.containerId);
        if (!this.container) {
            throw new Error(`Container with ID "${config.containerId}" not found`);
        }

        this.documentId = config.documentId;
        this.pages = config.pages || [];
        this.apiEndpoint = config.apiEndpoint || '/api/documents/reorder-pages';
        this.onReorder = config.onReorder || null;
        this.onSave = config.onSave || null;
        this.useHandles = config.useHandles !== false; // default true
        this.csrfToken = config.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        
        // State
        this.draggedItem = null;
        this.draggedIndex = -1;
        this.draggedPageId = null;
        this.isDragging = false;
        this.isSaving = false;
        this.originalOrder = [...this.pages];
        
        // Bind methods
        this.handleDragStart = this.handleDragStart.bind(this);
        this.handleDragOver = this.handleDragOver.bind(this);
        this.handleDragLeave = this.handleDragLeave.bind(this);
        this.handleDrop = this.handleDrop.bind(this);
        this.handleDragEnd = this.handleDragEnd.bind(this);
        
        // Initialize
        this.init();
    }

    /**
     * Initialize the agent
     */
    init() {
        this.renderPages();
        this.attachEvents();
        this.addDefaultStyles();
    }

    /**
     * Render pages in the container
     */
    renderPages() {
        if (!this.container) return;
        
        this.container.innerHTML = '';
        
        // Determine layout (grid or list based on container class)
        const isGrid = this.container.classList.contains('grid-view');
        
        this.pages.forEach((page, index) => {
            const element = this.createPageElement(page, index, isGrid);
            this.container.appendChild(element);
        });

        // Update page count display
        this.updatePageCount();
    }

    /**
     * Create a page element
     */
    createPageElement(page, index, isGrid) {
        const div = document.createElement('div');
        div.className = `document-page ${isGrid ? 'grid-item' : 'list-item'}`;
        div.setAttribute('draggable', 'true');
        div.setAttribute('data-page-id', page.id);
        div.setAttribute('data-page-number', page.number);
        div.setAttribute('data-index', index);
        
        // Page content
        div.innerHTML = `
            ${this.useHandles ? `
                <div class="page-drag-handle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="12" r="1"/>
                        <circle cx="15" cy="12" r="1"/>
                        <circle cx="9" cy="5" r="1"/>
                        <circle cx="15" cy="5" r="1"/>
                        <circle cx="9" cy="19" r="1"/>
                        <circle cx="15" cy="19" r="1"/>
                    </svg>
                </div>
            ` : ''}
            
            <div class="page-thumbnail">
                <img src="${page.thumbnail || page.url || '/api/placeholder/150/200'}" 
                     alt="Page ${page.number}"
                     loading="lazy"
                     onerror="this.src='/api/placeholder/150/200'">
                ${page.rotation ? `<div class="rotation-badge">${page.rotation}°</div>` : ''}
            </div>
            
            <div class="page-info">
                <span class="page-number-badge">${index + 1}</span>
                <span class="page-label">Page ${page.number}</span>
                ${page.size ? `<span class="page-size">${page.size}</span>` : ''}
            </div>
            
            <div class="page-actions">
                <button class="rotate-page-btn" data-page-id="${page.id}" title="Rotate page">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M1 12a11 11 0 0 0 11 11 11 11 0 0 0 11-11"/>
                        <path d="M4 12l3-3-3-3"/>
                    </svg>
                </button>
                <button class="delete-page-btn" data-page-id="${page.id}" title="Delete page">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>
                        <path d="M8 4V2h8v2"/>
                    </svg>
                </button>
            </div>
        `;
        
        return div;
    }

    /**
     * Attach drag and drop events
     */
    attachEvents() {
        const items = this.container.querySelectorAll('.document-page');
        
        items.forEach(item => {
            // Remove existing listeners
            item.removeEventListener('dragstart', this.handleDragStart);
            item.removeEventListener('dragover', this.handleDragOver);
            item.removeEventListener('dragleave', this.handleDragLeave);
            item.removeEventListener('drop', this.handleDrop);
            item.removeEventListener('dragend', this.handleDragEnd);

            // Add listeners
            item.addEventListener('dragstart', this.handleDragStart);
            item.addEventListener('dragover', this.handleDragOver);
            item.addEventListener('dragleave', this.handleDragLeave);
            item.addEventListener('drop', this.handleDrop);
            item.addEventListener('dragend', this.handleDragEnd);
        });

        // Attach button events
        this.attachButtonEvents();
    }

    /**
     * Attach button events (rotate, delete)
     */
    attachButtonEvents() {
        // Rotate buttons
        this.container.querySelectorAll('.rotate-page-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const pageId = btn.getAttribute('data-page-id');
                this.rotatePage(pageId);
            });
        });

        // Delete buttons
        this.container.querySelectorAll('.delete-page-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const pageId = btn.getAttribute('data-page-id');
                this.deletePage(pageId);
            });
        });
    }

    /**
     * Handle drag start
     */
    handleDragStart(e) {
        const item = e.currentTarget;
        
        // If using handles, check if drag started from handle
        if (this.useHandles) {
            const handle = item.querySelector('.page-drag-handle');
            if (handle && !handle.contains(e.target) && e.target !== handle) {
                e.preventDefault();
                return false;
            }
        }

        this.draggedItem = item;
        this.draggedIndex = parseInt(item.getAttribute('data-index'));
        this.draggedPageId = item.getAttribute('data-page-id');
        this.isDragging = true;
        
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', JSON.stringify({
            index: this.draggedIndex,
            pageId: this.draggedPageId
        }));
        
        // Add dragging class
        item.classList.add('dragging');
        
        // Show visual feedback
        this.showStatus('Dragging page...', 'info');
    }

    /**
     * Handle drag over
     */
    handleDragOver(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        const item = e.currentTarget;
        if (item !== this.draggedItem) {
            item.classList.add('drag-over');
        }
    }

    /**
     * Handle drag leave
     */
    handleDragLeave(e) {
        e.currentTarget.classList.remove('drag-over');
    }

    /**
     * Handle drop
     */
    handleDrop(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const targetItem = e.currentTarget;
        targetItem.classList.remove('drag-over');
        
        if (this.draggedItem && targetItem !== this.draggedItem) {
            const fromIndex = this.draggedIndex;
            const toIndex = parseInt(targetItem.getAttribute('data-index'));
            const targetPageId = targetItem.getAttribute('data-page-id');
            
            console.log(`Reordering page from index ${fromIndex} to ${toIndex}`);
            
            // Reorder the pages array
            const [movedPage] = this.pages.splice(fromIndex, 1);
            this.pages.splice(toIndex, 0, movedPage);
            
            // Update page numbers based on new order
            this.updatePageNumbers();
            
            // Re-render
            this.renderPages();
            this.attachEvents();
            
            // Call reorder callback
            if (this.onReorder) {
                this.onReorder({
                    fromIndex,
                    toIndex,
                    fromPageId: this.draggedPageId,
                    toPageId: targetPageId,
                    pages: this.pages
                });
            }
            
            // Show success message
            this.showStatus(`Page moved from position ${fromIndex + 1} to ${toIndex + 1}`, 'success');
            
            // Auto-save if enabled
            if (this.apiEndpoint) {
                this.saveToServer();
            }
        }
    }

    /**
     * Handle drag end
     */
    handleDragEnd(e) {
        // Remove all drag classes
        this.container.querySelectorAll('.document-page').forEach(item => {
            item.classList.remove('dragging', 'drag-over');
        });
        
        this.draggedItem = null;
        this.draggedIndex = -1;
        this.draggedPageId = null;
        this.isDragging = false;
        
        this.showStatus('Ready', 'info');
    }

    /**
     * Update page numbers based on current order
     */
    updatePageNumbers() {
        this.pages.forEach((page, index) => {
            page.number = index + 1;
            page.order = index + 1;
        });
    }

    /**
     * Save new page order to server
     */
    async saveToServer() {
        if (this.isSaving) return;
        
        this.isSaving = true;
        this.showStatus('Saving page order...', 'loading');
        
        try {
            // Prepare data for server
            const pageOrder = this.pages.map((page, index) => ({
                id: page.id,
                number: index + 1,
                order: index + 1
            }));

            // Send to server
            const response = await fetch(this.apiEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    document_id: this.documentId,
                    pages: pageOrder,
                    _token: this.csrfToken
                }),
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error(`Server responded with ${response.status}`);
            }

            const result = await response.json();
            
            if (result.success) {
                this.showStatus('Page order saved successfully', 'success');
                if (this.onSave) {
                    this.onSave(result);
                }
            } else {
                throw new Error(result.message || 'Failed to save order');
            }
            
        } catch (error) {
            console.error('Error saving page order:', error);
            this.showStatus('Failed to save page order', 'error');
            
            // Revert to original order on error
            this.pages = [...this.originalOrder];
            this.renderPages();
            this.attachEvents();
            
        } finally {
            this.isSaving = false;
        }
    }

    /**
     * Rotate a page
     */
    async rotatePage(pageId) {
        const pageIndex = this.pages.findIndex(p => p.id === pageId);
        if (pageIndex === -1) return;
        
        const page = this.pages[pageIndex];
        page.rotation = (page.rotation || 0) + 90;
        
        // Update UI
        const pageElement = this.container.querySelector(`[data-page-id="${pageId}"]`);
        if (pageElement) {
            const img = pageElement.querySelector('img');
            if (img) {
                img.style.transform = `rotate(${page.rotation}deg)`;
            }
        }
        
        this.showStatus(`Page rotated to ${page.rotation}°`, 'success');
        
        // Save rotation to server if needed
        if (this.apiEndpoint) {
            try {
                await fetch(this.apiEndpoint.replace('reorder-pages', 'rotate-page'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken
                    },
                    body: JSON.stringify({
                        document_id: this.documentId,
                        page_id: pageId,
                        rotation: page.rotation
                    })
                });
            } catch (error) {
                console.error('Error saving rotation:', error);
            }
        }
    }

    /**
     * Delete a page
     */
    async deletePage(pageId) {
        if (!confirm('Are you sure you want to delete this page?')) return;
        
        const pageIndex = this.pages.findIndex(p => p.id === pageId);
        if (pageIndex === -1) return;
        
        // Remove page
        this.pages.splice(pageIndex, 1);
        
        // Update page numbers
        this.updatePageNumbers();
        
        // Re-render
        this.renderPages();
        this.attachEvents();
        
        this.showStatus('Page deleted', 'success');
        
        // Save to server
        if (this.apiEndpoint) {
            try {
                await fetch(this.apiEndpoint.replace('reorder-pages', 'delete-page'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken
                    },
                    body: JSON.stringify({
                        document_id: this.documentId,
                        page_id: pageId
                    })
                });
            } catch (error) {
                console.error('Error deleting page:', error);
                this.showStatus('Failed to delete page', 'error');
            }
        }
    }

    /**
     * Update page count display
     */
    updatePageCount() {
        const countElement = document.getElementById('page-count');
        if (countElement) {
            countElement.textContent = `${this.pages.length} page${this.pages.length !== 1 ? 's' : ''}`;
        }
    }

    /**
     * Show status message
     */
    showStatus(message, type = 'info') {
        const statusElement = document.getElementById('page-reorder-status');
        if (statusElement) {
            statusElement.textContent = message;
            statusElement.className = `status-message ${type}`;
        }
        
        // Also show notification for important messages
        if (type === 'success' || type === 'error') {
            this.showNotification(message, type);
        }
        
        console.log(`[PageReorder] ${type}: ${message}`);
    }

    /**
     * Show notification
     */
    showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `page-reorder-notification ${type}`;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    /**
     * Add default CSS styles
     */
    addDefaultStyles() {
        // Check if styles already exist
        if (document.getElementById('page-reorder-styles')) return;

        const style = document.createElement('style');
        style.id = 'page-reorder-styles';
        style.textContent = `
            /* Container styles */
            .pages-container {
                display: flex;
                flex-wrap: wrap;
                gap: 16px;
                padding: 16px;
                background: #f9fafb;
                border-radius: 8px;
                min-height: 300px;
            }
            
            .pages-container.grid-view {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
            
            .pages-container.list-view {
                display: flex;
                flex-direction: column;
            }
            
            /* Page item styles */
            .document-page {
                position: relative;
                background: white;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                padding: 12px;
                transition: all 0.2s ease;
                cursor: grab;
                user-select: none;
            }
            
            .document-page.grid-item {
                width: 100%;
                aspect-ratio: 3/4;
                display: flex;
                flex-direction: column;
            }
            
            .document-page.list-item {
                display: flex;
                align-items: center;
                gap: 16px;
                width: 100%;
            }
            
            .document-page:hover {
                border-color: #3b82f6;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
                transform: translateY(-2px);
            }
            
            .document-page.dragging {
                opacity: 0.5;
                transform: scale(0.95);
                cursor: grabbing;
                border-color: #3b82f6;
                background: #f0f9ff;
                z-index: 1000;
            }
            
            .document-page.drag-over {
                border: 3px dashed #3b82f6;
                background: #f0f9ff;
                transform: scale(1.02);
            }
            
            /* Drag handle */
            .page-drag-handle {
                position: absolute;
                top: 8px;
                right: 8px;
                padding: 6px;
                background: rgba(255, 255, 255, 0.9);
                border-radius: 4px;
                cursor: grab;
                opacity: 0;
                transition: opacity 0.2s;
                z-index: 10;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .document-page:hover .page-drag-handle {
                opacity: 1;
            }
            
            .page-drag-handle:hover {
                background: white;
            }
            
            .page-drag-handle:active {
                cursor: grabbing;
            }
            
            /* Page thumbnail */
            .page-thumbnail {
                position: relative;
                width: 100%;
                height: 0;
                padding-bottom: 133%; /* 3:4 aspect ratio */
                overflow: hidden;
                border-radius: 4px;
                background: #f3f4f6;
                margin-bottom: 8px;
            }
            
            .list-item .page-thumbnail {
                width: 80px;
                height: 106px;
                padding-bottom: 0;
                flex-shrink: 0;
                margin-bottom: 0;
            }
            
            .page-thumbnail img {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                object-fit: cover;
                transition: transform 0.3s;
            }
            
            .rotation-badge {
                position: absolute;
                bottom: 4px;
                right: 4px;
                background: rgba(0, 0, 0, 0.6);
                color: white;
                font-size: 10px;
                padding: 2px 4px;
                border-radius: 2px;
            }
            
            /* Page info */
            .page-info {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-top: 8px;
            }
            
            .list-item .page-info {
                flex: 1;
                margin-top: 0;
            }
            
            .page-number-badge {
                width: 24px;
                height: 24px;
                background: #3b82f6;
                color: white;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 12px;
                font-weight: bold;
            }
            
            .page-label {
                font-size: 13px;
                font-weight: 500;
                color: #374151;
            }
            
            .page-size {
                font-size: 11px;
                color: #6b7280;
                background: #f3f4f6;
                padding: 2px 6px;
                border-radius: 4px;
            }
            
            /* Page actions */
            .page-actions {
                display: flex;
                gap: 4px;
                margin-top: 8px;
                opacity: 0;
                transition: opacity 0.2s;
            }
            
            .list-item .page-actions {
                margin-top: 0;
            }
            
            .document-page:hover .page-actions {
                opacity: 1;
            }
            
            .page-actions button {
                padding: 4px;
                border: none;
                background: #f3f4f6;
                border-radius: 4px;
                cursor: pointer;
                color: #4b5563;
                transition: all 0.2s;
            }
            
            .page-actions button:hover {
                background: #e5e7eb;
                color: #1f2937;
            }
            
            .page-actions .delete-page-btn:hover {
                background: #fee2e2;
                color: #dc2626;
            }
            
            /* Status message */
            .status-message {
                padding: 8px 12px;
                border-radius: 4px;
                font-size: 13px;
                margin-top: 12px;
            }
            
            .status-message.success {
                background: #d1fae5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }
            
            .status-message.error {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            
            .status-message.info {
                background: #dbeafe;
                color: #1e40af;
                border: 1px solid #bfdbfe;
            }
            
            .status-message.loading {
                background: #fef3c7;
                color: #92400e;
                border: 1px solid #fde68a;
            }
            
            /* Notifications */
            .page-reorder-notification {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 24px;
                border-radius: 8px;
                color: white;
                font-weight: 500;
                z-index: 9999;
                animation: slideIn 0.3s ease-out;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            }
            
            .page-reorder-notification.success {
                background: #10b981;
            }
            
            .page-reorder-notification.error {
                background: #ef4444;
            }
            
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        
        document.head.appendChild(style);
    }

    /**
     * Switch between grid and list view
     */
    setViewMode(mode) {
        this.container.classList.remove('grid-view', 'list-view');
        this.container.classList.add(mode === 'grid' ? 'grid-view' : 'list-view');
        this.renderPages();
        this.attachEvents();
    }

    /**
     * Get current page order
     */
    getPageOrder() {
        return this.pages.map((page, index) => ({
            id: page.id,
            number: index + 1,
            order: index + 1
        }));
    }

    /**
     * Get current pages
     */
    getPages() {
        return this.pages;
    }

    /**
     * Update pages data
     */
    setPages(newPages) {
        this.pages = newPages;
        this.originalOrder = [...newPages];
        this.renderPages();
        this.attachEvents();
        this.updatePageCount();
    }

    /**
     * Add a new page
     */
    addPage(pageData) {
        const newPage = {
            id: pageData.id || `page-${Date.now()}`,
            number: this.pages.length + 1,
            ...pageData
        };
        
        this.pages.push(newPage);
        this.renderPages();
        this.attachEvents();
        this.updatePageCount();
        
        this.showStatus('Page added', 'success');
    }
}

// ============================================
// USAGE EXAMPLE - Server-side document reordering
// ============================================

/*
// HTML Structure:
<div class="document-editor">
    <div class="toolbar">
        <button id="save-order-btn">Save Order</button>
        <button id="grid-view-btn">Grid View</button>
        <button id="list-view-btn">List View</button>
        <span id="page-count"></span>
    </div>
    
    <div id="page-container" class="pages-container grid-view"></div>
    
    <div id="page-reorder-status" class="status-message"></div>
</div>

// JavaScript:
document.addEventListener('DOMContentLoaded', function() {
    // Get document data from server
    const documentData = {
        id: 'doc-123',
        pages: [
            { id: 1, number: 1, url: '/uploads/page1.jpg', thumbnail: '/uploads/thumb1.jpg', size: 'A4' },
            { id: 2, number: 2, url: '/uploads/page2.jpg', thumbnail: '/uploads/thumb2.jpg', size: 'A4' },
            { id: 3, number: 3, url: '/uploads/page3.jpg', thumbnail: '/uploads/thumb3.jpg', size: 'A4' },
            { id: 4, number: 4, url: '/uploads/page4.jpg', thumbnail: '/uploads/thumb4.jpg', size: 'A4' },
            { id: 5, number: 5, url: '/uploads/page5.jpg', thumbnail: '/uploads/thumb5.jpg', size: 'A4' }
        ]
    };

    // Initialize the reorder agent
    const reorderAgent = new DocumentPageReorderAgent({
        containerId: 'page-container',
        documentId: documentData.id,
        pages: documentData.pages,
        apiEndpoint: '/api/documents/reorder-pages',
        csrfToken: document.querySelector('meta[name="csrf-token"]').content,
        useHandles: true,
        onReorder: (data) => {
            console.log('Pages reordered:', data);
            // Update any UI elements
            document.getElementById('save-order-btn').disabled = false;
        },
        onSave: (response) => {
            console.log('Saved to server:', response);
            document.getElementById('save-order-btn').disabled = true;
        }
    });

    // Save button
    document.getElementById('save-order-btn').addEventListener('click', () => {
        reorderAgent.saveToServer();
    });

    // View toggle buttons
    document.getElementById('grid-view-btn').addEventListener('click', () => {
        reorderAgent.setViewMode('grid');
    });

    document.getElementById('list-view-btn').addEventListener('click', () => {
        reorderAgent.setViewMode('list');
    });

    // Add a new page (example)
    document.getElementById('add-page-btn')?.addEventListener('click', () => {
        reorderAgent.addPage({
            id: `page-${Date.now()}`,
            url: '/uploads/new-page.jpg',
            thumbnail: '/uploads/new-thumb.jpg',
            size: 'A4'
        });
    });
});
*/

// ============================================
// EXPORT
// ============================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = DocumentPageReorderAgent;
}

if (typeof window !== 'undefined') {
    window.DocumentPageReorderAgent = DocumentPageReorderAgent;
}