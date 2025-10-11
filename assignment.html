<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment/Mortgage Deed</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            @page {
                size: A4 portrait;
                margin: 0.5in;
            }
            /* Remove browser headers and footers */
            @page { margin: 0; }
            body { margin: 1.6cm; }
        }
        
        .underline-field {
            border-bottom: 1px solid #000;
            display: inline-block;
            min-width: 150px;
            padding: 0 4px;
        }
        
        .form-line {
            border-bottom: 1px solid #000;
            min-height: 24px;
        }
        
        .document-title {
            text-decoration: underline;
            text-underline-offset: 4px;
        }
    </style>
</head>
<body class="bg-gray-50 p-4">
    <!-- Document Title Editor -->
    <div class="no-print fixed top-4 left-4 bg-white p-4 rounded-lg shadow-lg z-50 max-w-sm">
        <h3 class="font-bold text-lg mb-2">Customize Document</h3>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Type</label>
                <div class="flex space-x-2">
                    <button 
                        onclick="setDocumentType('ASSIGNMENT')" 
                        class="flex-1 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors"
                    >
                        ASSIGNMENT
                    </button>
                    <button 
                        onclick="setDocumentType('MORTGAGE')" 
                        class="flex-1 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors"
                    >
                        MORTGAGE
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Custom Document Title</label>
                <input 
                    type="text" 
                    id="title-input" 
                    value="ASSIGNMENT" 
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter custom document title"
                >
            </div>
            <button 
                onclick="updateDocumentText()" 
                class="w-full bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors"
            >
                Update Document
            </button>
        </div>
    </div>

    <!-- Print Button -->
    <button onclick="window.print()" class="no-print fixed top-4 right-4 bg-blue-600 text-white px-4 py-2 rounded-lg shadow-lg hover:bg-blue-700 transition-colors flex items-center gap-2 z-50">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z" clip-rule="evenodd" />
        </svg>
        Print Document
    </button>

    <!-- Document Container -->
    <div class="max-w-4xl mx-auto bg-white shadow-lg p-12 relative">
        <!-- Page Number -->
        <div class="absolute top-4 right-12 text-red-500 text-lg font-semibold no-print">39</div>
        
        <!-- Hole Punch Indicator -->
        <div class="absolute left-4 top-32 w-4 h-4 rounded-full border-2 border-gray-400 bg-white no-print"></div>

        <!-- Title -->
        <div class="text-center mb-8">
            <h1 id="document-title" class="text-2xl font-bold tracking-wider mb-2 document-title">ASSIGNMENT</h1>
        </div>

        <!-- Subtitle -->
        <div class="text-center mb-8">
            <p id="document-subtitle" class="italic text-sm">THIS IS A DEED OF ASSIGNMENT</p>
        </div>

        <!-- Form Content -->
        <div class="space-y-6 text-sm leading-relaxed">
            <!-- Date Line -->
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">Dated:</span>
                <span class="underline-field flex-1"></span>
                <span>day of</span>
                <span class="underline-field flex-1"></span>
                <span>20</span>
                <span class="underline-field w-16"></span>
            </div>

            <!-- Executed By -->
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">Executed by:</span>
                <span class="form-line flex-1"></span>
            </div>

            <!-- Of -->
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">Of:</span>
                <span class="form-line flex-1"></span>
            </div>

            <!-- Right of Occupancy -->
            <div class="mt-6">
                <div class="flex items-baseline gap-2 flex-wrap">
                    <span>The Right of Occupancy No.</span>
                    <span class="underline-field flex-1 min-w-[200px]"></span>
                    <span id="assignment-mortgage-text">is Assigned</span>
                </div>
            </div>

            <!-- To -->
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">to:</span>
                <span class="form-line flex-1"></span>
            </div>

            <!-- Of (second) -->
            <div class="flex items-baseline gap-2">
                <span class="font-semibold">of:</span>
                <span class="form-line flex-1"></span>
            </div>

            <!-- From Date -->
            <div class="flex items-baseline gap-2 mt-6">
                <span class="font-semibold">From:</span>
                <span class="underline-field flex-1"></span>
                <span>day of</span>
                <span class="underline-field flex-1"></span>
                <span>20</span>
                <span class="underline-field w-16"></span>
            </div>

            <!-- Right-aligned fee section -->
            <div class="mt-8 space-y-2 max-w-md ml-auto">
                <!-- Consideration -->
                <div class="flex items-baseline gap-2">
                    <span class="font-semibold uppercase tracking-wide">CONSIDERATION</span>
                    <span class="form-line flex-1"></span>
                </div>

                <!-- Stamp Duty -->
                <div class="flex items-baseline gap-2">
                    <span class="font-semibold uppercase tracking-wide">STAMP DUTY:</span>
                    <span class="form-line flex-1"></span>
                </div>

                <!-- Registration Fee -->
                <div class="flex items-baseline gap-2">
                    <span class="font-semibold uppercase tracking-wide">REGISTRATION FEE</span>
                    <span class="form-line flex-1"></span>
                </div>
            </div>

            <!-- Footer Note -->
            <div class="text-right text-xs mt-4 text-gray-500 no-print">
                <span class="italic">...to be REGISTERED</span>
            </div>
        </div>
    </div>

    <script>
        let currentDocumentType = 'ASSIGNMENT';
        
        function setDocumentType(type) {
            currentDocumentType = type;
            document.getElementById('title-input').value = type;
            updateDocumentText();
        }
        
        function updateDocumentText() {
            const titleInput = document.getElementById('title-input');
            const documentTitle = document.getElementById('document-title');
            const documentSubtitle = document.getElementById('document-subtitle');
            const assignmentMortgageText = document.getElementById('assignment-mortgage-text');
            
            let title = titleInput.value.trim();
            if (title === '') {
                title = 'ASSIGNMENT';
            }
            
            documentTitle.textContent = title.toUpperCase();
            
            // Determine document type based on title
            let docType = 'ASSIGNMENT';
            if (title.toUpperCase().includes('MORTGAGE')) {
                docType = 'MORTGAGE';
            }
            
            // Update subtitle based on document type
            if (docType === 'ASSIGNMENT') {
                documentSubtitle.textContent = 'THIS IS A DEED OF ASSIGNMENT';
                assignmentMortgageText.textContent = 'is Assigned';
            } else {
                documentSubtitle.textContent = 'THIS IS A DEED OF MORTGAGE';
                assignmentMortgageText.textContent = 'is Mortgaged';
            }
        }
        
        // Initialize with the default text
        document.getElementById('title-input').value = 'ASSIGNMENT';
        updateDocumentText();
    </script>
</body>
</html>