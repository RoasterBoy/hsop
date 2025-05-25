jQuery(document).ready(function ($) {
    // --- EXPORT ---
    const exportBtn = $('#cemetery-export-button');
    const exportStatus = $('#cemetery-export-status');
    const exportProgress = $('#cemetery-export-progress-bar');

    if (exportBtn.length) {
        exportBtn.on('click', async function () {
            exportBtn.prop('disabled', true);
            exportStatus.text('Starting export...');
            exportProgress.css('width', '0%').show();
            let accumulatedData = [];
            try {
                const initialResponse = await $.ajax({ url: cemeteryIO.ajax_url, type: 'POST', data: { action: 'cemetery_export_preflight', _ajax_nonce: cemeteryIO.nonce } });
                if (!initialResponse.success) { throw new Error(initialResponse.data.message || 'Could not get record count.'); }
                const totalRecords = initialResponse.data.total_records;
                const totalPages = initialResponse.data.total_pages;
                if (totalRecords === 0) { exportStatus.text('No records to export.'); exportBtn.prop('disabled', false); exportProgress.hide(); return; }
                exportStatus.text(`Found ${totalRecords} records. Starting download...`);
                for (let page = 1; page <= totalPages; page++) {
                    const batchResponse = await $.ajax({ url: cemeteryIO.ajax_url, type: 'POST', data: { action: 'cemetery_export_batch', _ajax_nonce: cemeteryIO.nonce, page: page } });
                    if (batchResponse.success) {
                        accumulatedData = accumulatedData.concat(batchResponse.data);
                        const progressPercent = (accumulatedData.length / totalRecords) * 100;
                        exportProgress.css('width', progressPercent + '%');
                        exportStatus.text(`Processed ${accumulatedData.length} of ${totalRecords} records...`);
                    } else { throw new Error(batchResponse.data.message || `Error on page ${page}.`); }
                }
                // MODIFIED: Call downloadJSON instead of convertToCSV
                downloadJSON(accumulatedData);
                exportStatus.text(`Export complete! ${totalRecords} records downloaded.`);
            } catch (error) { console.error('Export failed:', error); exportStatus.text('Export failed. Check console for details.'); }
            finally { exportBtn.prop('disabled', false); }
        });

        // NEW function to handle JSON download
        function downloadJSON(data) {
            const jsonString = JSON.stringify(data, null, 2); // Pretty-print the JSON
            const blob = new Blob([jsonString], { type: 'application/json;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'cemetery-records-export.json'); // Set filename to .json
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    // --- IMPORT ---
    const importBtn = $('#cemetery-import-button');
    const importStatus = $('#cemetery-import-status');
    const importProgress = $('#cemetery-import-progress-bar');
    const fileInput = $('#cemetery-import-file');
    if (importBtn.length) {
        importBtn.on('click', async function () {
            if (!fileInput[0].files.length) { importStatus.text('Please select a JSON file to import.'); return; }
            importBtn.prop('disabled', true); fileInput.prop('disabled', true); importStatus.text('Reading and parsing JSON file...'); importProgress.css('width', '0%').show();
            const file = fileInput[0].files[0];

            // MODIFIED: Use JSON.parse instead of a CSV parser
            let records;
            try {
                const jsonText = await file.text();
                records = JSON.parse(jsonText);
            } catch (e) {
                importStatus.text('Error: Invalid JSON file. Please check the file syntax.');
                resetImportUI();
                return;
            }

            if (!records || !Array.isArray(records) || records.length === 0) {
                importStatus.text('Could not parse JSON or file is empty/invalid.');
                resetImportUI();
                return;
            }

            const totalRecords = records.length; const batchSize = 50; const totalBatches = Math.ceil(totalRecords / batchSize);
            let recordsProcessed = 0, createdCount = 0, updatedCount = 0;
            importStatus.text(`Found ${totalRecords} records. Starting import...`);
            try {
                for (let i = 0; i < totalBatches; i++) {
                    const batch = records.slice(i * batchSize, (i + 1) * batchSize);
                    const response = await $.ajax({ url: cemeteryIO.ajax_url, type: 'POST', data: { action: 'cemetery_import_batch', _ajax_nonce: cemeteryIO.nonce, records: JSON.stringify(batch) } });
                    if (response.success) {
                        recordsProcessed += batch.length; createdCount += response.data.created; updatedCount += response.data.updated;
                        const progressPercent = (recordsProcessed / totalRecords) * 100;
                        importProgress.css('width', progressPercent + '%');
                        importStatus.text(`Processed ${recordsProcessed} of ${totalRecords} records...`);
                    } else { throw new Error(response.data.message || 'An unknown error occurred during import.'); }
                }
                importStatus.text(`Import complete! ${createdCount} records created, ${updatedCount} records updated.`);
            } catch (error) { console.error('Import failed:', error); importStatus.text('Import failed. ' + error.message); }
            finally { resetImportUI(); }
        });
        function resetImportUI() { importBtn.prop('disabled', false); fileInput.prop('disabled', false); fileInput.val(''); }
    }

    // --- DELETE ---
    const deleteBtn = $('#cemetery-delete-all-button');
    const deleteStatus = $('#cemetery-delete-status');

    if (deleteBtn.length) {
        deleteBtn.on('click', async function() {
            if (!confirm('DANGER: Are you sure you want to permanently delete ALL cemetery records? This action cannot be undone.')) {
                deleteStatus.text('Deletion cancelled.');
                return;
            }
            const confirmationText = prompt('This is the final confirmation. To proceed, please type DELETE in the box below.');
            if (confirmationText !== 'DELETE') {
                deleteStatus.text('Deletion cancelled. Confirmation text did not match.');
                return;
            }
            deleteBtn.prop('disabled', true);
            deleteStatus.text('Deletion in progress... Please do not leave this page.');
            let totalDeleted = 0;
            try {
                while (true) {
                    const response = await $.ajax({ url: cemeteryIO.ajax_url, type: 'POST', data: { action: 'cemetery_delete_all_batch', _ajax_nonce: cemeteryIO.nonce } });
                    if (response.success) {
                        if (response.data.deleted === 0) { break; }
                        totalDeleted += response.data.deleted;
                        deleteStatus.text(`Deleted ${totalDeleted} records so far...`);
                    } else { throw new Error(response.data.message || 'An unknown error occurred during deletion.'); }
                }
                deleteStatus.text(`Deletion complete! All ${totalDeleted} records have been permanently removed.`);
            } catch (error) { console.error('Deletion failed:', error); deleteStatus.text('Deletion failed. ' + error.message); }
            finally { deleteBtn.prop('disabled', false); }
        });
    }
});