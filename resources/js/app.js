/**
 * Async "Add to Cart" for the public storefront.
 *
 * Submits the existing server-rendered add-to-cart forms via fetch instead
 * of a normal form submission, so the page never reloads. The server still
 * does all validation (product belongs to this business, is available,
 * quantity limits) — this only changes how the response is delivered.
 *
 * No-ops everywhere else in the app: pages without a
 * [data-add-to-cart-form] simply have nothing to attach to.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-add-to-cart-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            handleAddToCart(form);
        });
    });
});

async function handleAddToCart(form) {
    const button = form.querySelector('[data-add-to-cart-button]');
    const originalLabel = button.textContent;

    button.disabled = true;
    button.textContent = 'Adding…';

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            body: new FormData(form),
        });

        if (!response.ok) {
            throw new Error('add-to-cart request failed');
        }

        const data = await response.json();

        document.querySelectorAll('[data-cart-count]').forEach((el) => {
            el.textContent = data.cartCount;
        });

        button.textContent = 'Added ✓';
    } catch (error) {
        button.textContent = 'Try again';
    } finally {
        setTimeout(() => {
            button.textContent = originalLabel;
            button.disabled = false;
        }, 1500);
    }
}
