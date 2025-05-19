jQuery(document).ready(function($) {
    'use strict';

    let isImporting = false;
    const progressBar = $('.cemetery-records-progress-bar');
    const progressText = $('.cemetery-records-progress-text');

    // Initialize import form
    function initImportForm() {
        const $form = $('#cemetery-records-import-form');
        
        if (!$form.length) return;

        $form.on('submit', function(e) {
            e.preventDefault();
            
            if (isImporting) {
                return false;
            }

            if (!validateForm()) {
                return false;
            }

            submitForm($(this));
        });
    }

    // Save paths to session storage and server
    function savePaths(callback) {
        const extractedPath = $('#extracted_images_path').val().trim();
        const sourcePath = $('#source_pages_path').val().trim();

        // Save to session storage
        try {
            sessionStorage.setItem('extracted_images_path', extractedPath);
            sessionStorage.setItem('source_pages_path', sourcePath);
        } catch (e) {
            console.warn('Failed to save paths to session storage:', e);
        }

        // Save to server
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'save_image_paths',
                extracted_images_path: extractedPath,
                source_pages_path: sourcePath,
                nonce: cemeteryRecordsImport.nonce
            },
            success: function(response) {
                if (typeof callback === 'function') {
                    callback(response.success);
                }
            },
            error: function() {
                if (typeof callback === 'function') {
                    callback(false);
                }
            }
        });
    }

    // Validate form inputs
    function validateForm() {
        const $fileInput = $('#import_file');
        const $extractedPath = $('#extracted_images_path');
        const $sourcePath = $('#source_pages_path');
        
        if (!$fileInput[0].files.length) {
            showError('Please select a JSON file to import.');
            return false;
        }

        if (!$extractedPath.val().trim()) {
            showError('Please enter the extracted images directory path.');
            return false;
        }

        if (!$sourcePath.val().trim()) {
            showError('Please enter the source pages directory path.');
            return false;
        }

        return true;
    }

    // Submit the form
    function submitForm($form) {
        isImporting = true;
        showProgress();
        updateProgress(0, 'Starting import...');

        const $submitButton = $form.find('input[type="submit"]');
        $submitButton.prop('disabled', true).val('Importing...');

        const formData = new FormData($form[0]);
        formData.append('action', 'start_import');
        
        // First, upload the file and start the import process
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    const importId = response.data.import_id;
                    startProgressPolling(importId);
                } else {
                    showError(response.data.message || 'Failed to start import');
                    resetImportForm();
                }
            },
            error: function(xhr, status, error) {
                showError('Failed to start import: ' + error);
                resetImportForm();
            }
        });
    }

    function startProgressPolling(importId) {
        const pollInterval = setInterval(function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'check_import_progress',
                    import_id: importId,
                    nonce: cemeteryRecordsImport.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const progress = response.data;
                        updateProgress(
                            progress.percent_complete,
                            `Processing record ${progress.current_record} of ${progress.total_records} (${progress.percent_complete}%)`
                        );
                        
                        if (progress.status === 'completed') {
                            clearInterval(pollInterval);
                            showSuccess('Import completed successfully!');
                            resetImportForm();
                        } else if (progress.status === 'error') {
                            clearInterval(pollInterval);
                            showError(progress.message || 'Import failed');
                            resetImportForm();
                        }
                    }
                },
                error: function() {
                    // Don't clear interval on network errors, keep trying
                    showError('Failed to check progress. Retrying...');
                }
            });
        }, 5000); // Poll every 5 seconds
        
        // Store interval ID in sessionStorage to maintain polling across page refreshes
        sessionStorage.setItem('importProgressInterval', pollInterval);
    }

    function resetImportForm() {
        isImporting = false;
        const $submitButton = $('#cemetery-records-import-form input[type="submit"]');
        $submitButton.prop('disabled', false).val('Import Records');
    }

    // Check for ongoing import on page load
    function checkOngoingImport() {
        const importId = sessionStorage.getItem('currentImportId');
        if (importId) {
            isImporting = true;
            showProgress();
            startProgressPolling(importId);
        }
    }

    // Show progress bar
    function showProgress() {
        $('.cemetery-records-progress').show();
    }

    // Hide progress bar
    function hideProgress() {
        $('.cemetery-records-progress').hide();
    }

    // Update progress bar and text
    function updateProgress(percent, message) {
        progressBar.css('width', percent + '%');
        progressText.text(message);
    }

    // Show success message
    function showSuccess(message) {
        const html = `
            <div class="cemetery-records-success">
                <p>${message}</p>
            </div>
        `;
        $('.cemetery-records-messages').html(html);
    }

    // Show error message
    function showError(message) {
        const html = `
            <div class="cemetery-records-error">
                <p>${message}</p>
            </div>
        `;
        $('.cemetery-records-messages').html(html);
    }

    // Handle file input changes
    function handleFileInput() {
        $('#import_file').on('change', function() {
            const file = this.files[0];
            if (file) {
                if (file.size > 50 * 1024 * 1024) { // 50MB limit
                    showError('File size exceeds 50MB limit');
                    this.value = '';
                    return;
                }
                
                if (file.type !== 'application/json') {
                    showError('Please select a JSON file');
                    this.value = '';
                    return;
                }
            }
        });
    }

    // Handle directory path inputs
    function handleDirectoryInputs() {
        // Restore values from session storage on page load
        $('.image-paths input[type="text"]').each(function() {
            const $input = $(this);
            try {
                const savedPath = sessionStorage.getItem($input.attr('id'));
                if (savedPath) {
                    $input.val(savedPath);
                }
            } catch (e) {
                console.warn('Failed to restore path from session storage:', e);
            }
        });

        // Save values to session storage on input
        $('.image-paths input[type="text"]').on('input', function() {
            const $input = $(this);
            const path = $input.val().trim();
            try {
                sessionStorage.setItem($input.attr('id'), path);
            } catch (e) {
                console.warn('Failed to store path in session storage:', e);
            }
        });
    }

    // Initialize delete confirmation
    function initDeleteConfirmation() {
        $('#delete-all-form').on('submit', function(e) {
            if (!confirm('Are you sure you want to delete all cemetery records? This action cannot be undone.')) {
                e.preventDefault();
            }
        });
    }

    // Initialize all functionality
    function init() {
        initImportForm();
        handleFileInput();
        handleDirectoryInputs();
        initDeleteConfirmation();
        checkOngoingImport();
    }

    // Start the application
    init();

    // Handle delete all confirmation
    $('#delete-all-form').on('submit', function(e) {
        if (!confirm('WARNING: This will permanently delete ALL cemetery records and their associated images. This action cannot be undone. Are you absolutely sure you want to continue?')) {
            e.preventDefault();
            return false;
        }
    });

    // File input change handler
    $('#import_file').on('change', function() {
        var $fileInput = $(this);
        var $fileLabel = $('.file-label');
        
        if ($fileInput[0].files.length) {
            var fileName = $fileInput[0].files[0].name;
            $fileLabel.text('Selected file: ' + fileName);
        } else {
            $fileLabel.text('No file selected');
        }
    });

    // Path input validation
    $('.image-paths input[type="text"]').on('blur', function() {
        var $input = $(this);
        var path = $input.val().trim();
        
        if (path) {
            // Allow any non-empty path - server will validate
            $input.removeClass('error');
        }
    });

    // Export button handler
    $('.export-records-button').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true);
        $button.val('Exporting...');
        
        // Re-enable after delay to prevent double-clicks
        setTimeout(function() {
            $button.prop('disabled', false);
            $button.val('Export Records');
        }, 2000);
    });

    // Handle window beforeunload
    $(window).on('beforeunload', function() {
        if (isImporting) {
            return 'Import in progress. Are you sure you want to leave?';
        }
    });
}); 