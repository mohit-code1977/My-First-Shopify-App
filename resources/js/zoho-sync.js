/*--------------- ZOHO SYNC DASHBOARD ---------------*/
document.addEventListener('DOMContentLoaded', () => {

    /*----------------- ELEMENTS -----------------*/
    const searchInput = document.getElementById('searchInput');

    const statusFilter = document.getElementById('statusFilter');

    const productRows = document.querySelectorAll('.product-row');

    const noResults = document.getElementById('noResults');

    const syncAllButton = document.getElementById('syncAllButton');

    const syncButtons = document.querySelectorAll('.sync-button');


    /*-------------- CSRF TOKEN --------------*/
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;


    /*-------------- SEARCH + STATUS FILTER --------------*/
    function filterProducts() {
        const search = searchInput ? searchInput.value.trim().toLowerCase(): '';
        const status = statusFilter ? statusFilter.value : 'all';

        let visibleRows = 0;

        productRows.forEach(row => {
            const rowText = row.innerText.toLowerCase();
            const rowStatus = row.dataset.status;
            const matchesSearch = rowText.includes(search);

            const matchesStatus = status === 'all' || rowStatus === status;

            const shouldShow = matchesSearch && matchesStatus;

            row.style.display = shouldShow ? '' : 'none';

            if (shouldShow) {
                visibleRows++;
            }

        });

        if (noResults) {
            noResults.hidden = visibleRows !== 0;
        }
    }

    /*------------- SEARCH LISTENER -------------*/
    if (searchInput) {
        searchInput.addEventListener('input',filterProducts);
    }

    /*--------------STATUS FILTER LISTENER -------------*/
    if (statusFilter) {
        statusFilter.addEventListener('change',filterProducts);
    }


    /*--------------- INDIVIDUAL SYNC ---------------*/
    syncButtons.forEach(button => {

        button.addEventListener('click', async () => {
                const variantId = button.dataset.variantId;

                if (!variantId) {
                    console.error('Variant ID is missing.');
                    return;
                }

                /*------------ Prevent double-click ------------*/
                button.disabled = true;
                const originalText = button.innerText;
                button.innerText = 'Syncing...';

                try {
                    const response = await fetch(`/zoho/sync/${variantId}`,{
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json'
                                }
                            }
                        );

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Synchronization failed.');
                    }

                    console.log('Sync successful:',data);

                    button.innerText = 'Synced';

                } catch (error) {
                    console.error('Sync failed:', error);
                    button.innerText = 'Failed';
                } 
                finally {
                    setTimeout(() => {
                        button.disabled = false;
                        button.innerText = originalText;
                    }, 1500);

                }

            }
        );

    });


    /*--------------- SYNC ALL ---------------*/
    if (syncAllButton) {

        syncAllButton.addEventListener('click', async () => {

                const originalText = syncAllButton.innerText;
                syncAllButton.disabled = true;
                syncAllButton.innerText = 'Syncing...';
                
                try {
                    const response = await fetch('/zoho/sync-all',{
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept':'application/json'
                                }
                            }
                        );

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Synchronization failed.');
                    }

                    console.log('Sync all successful:', data);

                    const summary = data.data;

                    syncAllButton.innerText = `Done: ${summary.created} created, ${summary.updated} updated`;

                } catch (error) {
                    console.error('Sync all failed:',error);

                    syncAllButton.innerText = 'Sync Failed';
                } finally {
                    setTimeout(() => {
                        syncAllButton.disabled = false;
                        syncAllButton.innerText = originalText;
                    }, 2500);
                }
            }
        );
    }


    /*-------------- INITIAL FILTER --------------*/
    filterProducts();
});