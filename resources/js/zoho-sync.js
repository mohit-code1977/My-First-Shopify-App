/**
 * =========================================================
 * ZOHO SYNC DASHBOARD
 * =========================================================
 */


document.addEventListener('DOMContentLoaded', () => {

    /*
     * -------------------------------------------------------
     * ELEMENTS
     * -------------------------------------------------------
     */

    const searchInput =
        document.getElementById('searchInput');

    const statusFilter =
        document.getElementById('statusFilter');

    const productRows =
        document.querySelectorAll('.product-row');

    const noResults =
        document.getElementById('noResults');

    const syncAllButton =
        document.getElementById('syncAllButton');

    const syncButtons =
        document.querySelectorAll('.sync-button');


    /*
     * -------------------------------------------------------
     * SEARCH + STATUS FILTER
     * -------------------------------------------------------
     */

    function filterProducts() {

        const search =
            searchInput
                ? searchInput.value
                    .trim()
                    .toLowerCase()
                : '';

        const status =
            statusFilter
                ? statusFilter.value
                : 'all';


        let visibleRows = 0;


        productRows.forEach(row => {

            const rowText =
                row.innerText.toLowerCase();

            const rowStatus =
                row.dataset.status;


            const matchesSearch =
                rowText.includes(search);


            const matchesStatus =
                status === 'all' ||
                rowStatus === status;


            const shouldShow =
                matchesSearch &&
                matchesStatus;


            row.style.display =
                shouldShow
                    ? ''
                    : 'none';


            if (shouldShow) {
                visibleRows++;
            }

        });


        /*
         * Show "no results" message
         */

        if (noResults) {

            noResults.hidden =
                visibleRows !== 0;

        }

    }


    /*
     * Search listener
     */

    if (searchInput) {

        searchInput.addEventListener(
            'input',
            filterProducts
        );

    }


    /*
     * Status listener
     */

    if (statusFilter) {

        statusFilter.addEventListener(
            'change',
            filterProducts
        );

    }


    /*
     * -------------------------------------------------------
     * INDIVIDUAL SYNC BUTTON
     * -------------------------------------------------------
     *
     * Backend endpoint will be connected here.
     */

    syncButtons.forEach(button => {

        button.addEventListener(
            'click',
            async () => {

                const productId =
                    button.dataset.productId;

                const variantId =
                    button.dataset.variantId;


                if (!productId) {
                    return;
                }


                /*
                 * Prevent double-click
                 */

                button.disabled = true;


                const originalText =
                    button.innerText;


                button.innerText =
                    'Syncing...';


                try {

                    /*
                     * ------------------------------------------------
                     * IMPORTANT
                     * ------------------------------------------------
                     *
                     * We will connect your Laravel sync endpoint here.
                     *
                     * Example:
                     *
                     * fetch(`/zoho/sync/${productId}`, {
                     *     method: 'POST',
                     *     headers: {
                     *         'X-CSRF-TOKEN': csrfToken,
                     *         'Accept': 'application/json'
                     *     }
                     * });
                     *
                     * ------------------------------------------------
                     */


                    console.log(
                        'Sync requested:',
                        {
                            productId,
                            variantId
                        }
                    );


                    /*
                     * Temporary delay so UI behavior can be tested.
                     *
                     * REMOVE this when backend endpoint is connected.
                     */

                    await new Promise(
                        resolve =>
                            setTimeout(resolve, 700)
                    );


                    button.innerText =
                        'Synced';


                } catch (error) {

                    console.error(
                        'Sync failed:',
                        error
                    );


                    button.innerText =
                        'Failed';


                } finally {

                    setTimeout(() => {

                        button.disabled =
                            false;

                        button.innerText =
                            originalText;

                    }, 1200);

                }

            }
        );

    });


    /*
     * -------------------------------------------------------
     * SYNC ALL
     * -------------------------------------------------------
     */

    if (syncAllButton) {

        syncAllButton.addEventListener(
            'click',
            async () => {

                const originalText =
                    syncAllButton.innerText;


                syncAllButton.disabled =
                    true;


                syncAllButton.innerText =
                    'Syncing...';


                try {

                    /*
                     * Backend endpoint will be connected here.
                     *
                     * Example:
                     *
                     * await fetch('/zoho/sync-all', {
                     *     method: 'POST',
                     *     headers: {
                     *         'X-CSRF-TOKEN': csrfToken,
                     *         'Accept': 'application/json'
                     *     }
                     * });
                     */


                    console.log(
                        'Sync all products requested'
                    );


                    /*
                     * Temporary UI test.
                     *
                     * REMOVE after backend integration.
                     */

                    await new Promise(
                        resolve =>
                            setTimeout(resolve, 1000)
                    );


                    syncAllButton.innerText =
                        'Sync Complete';


                } catch (error) {

                    console.error(
                        'Sync all failed:',
                        error
                    );


                    syncAllButton.innerText =
                        'Sync Failed';


                } finally {

                    setTimeout(() => {

                        syncAllButton.disabled =
                            false;

                        syncAllButton.innerText =
                            originalText;

                    }, 1500);

                }

            }
        );

    }


    /*
     * -------------------------------------------------------
     * INITIAL FILTER
     * -------------------------------------------------------
     */

    filterProducts();

});