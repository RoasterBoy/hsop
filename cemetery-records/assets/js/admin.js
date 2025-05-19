jQuery(document).ready(function($) {
    // Initialize media uploader
    var mediaUploader;
    
    $('.upload-image-button').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var fieldId = button.data('field');
        var previewId = '#' + fieldId + '_preview';
        
        // If the uploader object has already been created, reopen the dialog
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        
        // Create the media uploader
        mediaUploader = wp.media({
            title: 'Select Image',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        // When an image is selected, run a callback
        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            
            // Set the field value
            $('#' + fieldId).val(attachment.id);
            
            // Update the preview
            var preview = $(previewId);
            if (attachment.type === 'image') {
                if (preview.find('img').length) {
                    preview.find('img').attr('src', attachment.url);
                } else {
                    preview.html('<img src="' + attachment.url + '" alt="" style="max-width: 100%; height: auto;">');
                }
            }
        });

        // Open the uploader dialog
        mediaUploader.open();
    });

    // Remove image button
    $('.remove-image-button').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var fieldId = button.data('field');
        var previewId = '#' + fieldId + '_preview';
        
        // Clear the field value
        $('#' + fieldId).val('');
        
        // Clear the preview
        $(previewId).empty();
    });

    // Form validation
    $('#post').on('submit', function(e) {
        var title = $('#title').val();
        var imageCaption = $('#image_caption').val();
        var extractedImage = $('#extracted_image').val();
        
        if (!title) {
            e.preventDefault();
            alert('Please enter a title for this record.');
            $('#title').focus();
            return false;
        }
        
        if (!imageCaption) {
            if (!confirm('No image caption provided. Do you want to continue?')) {
                e.preventDefault();
                $('#image_caption').focus();
                return false;
            }
        }
        
        if (!extractedImage) {
            if (!confirm('No extracted image selected. Do you want to continue?')) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Auto-save warning
    var formModified = false;
    $('#post').on('change keyup', ':input', function() {
        formModified = true;
    });
    
    window.onbeforeunload = function() {
        if (formModified) {
            return 'You have unsaved changes. Do you want to leave this page?';
        }
    };
    
    $('#post').on('submit', function() {
        formModified = false;
    });

    // Image preview modal
    $('.cemetery-record-image img').on('click', function() {
        var $img = $(this);
        var $modal = $('<div>').addClass('image-preview-modal');
        var $modalContent = $('<div>').addClass('image-preview-modal-content');
        var $modalImage = $('<img>').attr('src', $img.attr('src'));
        var $closeButton = $('<span>').addClass('close-modal').html('&times;');
        
        $modalContent.append($closeButton, $modalImage);
        $modal.append($modalContent);
        $('body').append($modal);
        
        $modal.fadeIn();
        
        $closeButton.add($modal).on('click', function() {
            $modal.fadeOut(function() {
                $(this).remove();
            });
        });
        
        $modalContent.on('click', function(e) {
            e.stopPropagation();
        });
    });

    // Add CSS for the modal
    $('<style>')
        .text(`
            .image-preview-modal {
                display: none;
                position: fixed;
                z-index: 999999;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.9);
            }
            .image-preview-modal-content {
                position: relative;
                max-width: 90%;
                max-height: 90%;
                margin: 2% auto;
                text-align: center;
            }
            .image-preview-modal-content img {
                max-width: 100%;
                max-height: 90vh;
            }
            .close-modal {
                position: absolute;
                top: -30px;
                right: 0;
                color: #fff;
                font-size: 30px;
                font-weight: bold;
                cursor: pointer;
            }
        `)
        .appendTo('head');
}); 