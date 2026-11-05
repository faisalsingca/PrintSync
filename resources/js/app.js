import { Passkeys } from '@laravel/passkeys';

// Ensure Livewire's HTML parser preserves the active theme class on documentElement during wire:navigate
const originalParseFromString = DOMParser.prototype.parseFromString;
DOMParser.prototype.parseFromString = function (str, type) {
    const doc = originalParseFromString.apply(this, arguments);
    if (type === 'text/html' && doc?.documentElement && document?.documentElement) {
        const isDark = document.documentElement.classList.contains('dark');
        doc.documentElement.classList.toggle('dark', isDark);
    }
    return doc;
};

document.addEventListener('livewire:navigating', (event) => {
    document.documentElement.classList.add('ps-navigating');
    const isDark = document.documentElement.classList.contains('dark');

    event.detail.onSwap(() => {
        document.documentElement.classList.toggle('dark', isDark);
    });
});

document.addEventListener('livewire:navigated', () => {
    document.documentElement.classList.remove('ps-navigating');
    const isDark = document.documentElement.classList.contains('dark');
    document.cookie = `flux_appearance=${isDark ? 'dark' : 'light'}; path=/; max-age=31536000; SameSite=Lax`;
});

// Keep cookie synchronized whenever theme class changes
if (typeof MutationObserver !== 'undefined' && document.documentElement) {
    const themeObserver = new MutationObserver(() => {
        const isDark = document.documentElement.classList.contains('dark');
        document.cookie = `flux_appearance=${isDark ? 'dark' : 'light'}; path=/; max-age=31536000; SameSite=Lax`;
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const button = event.target.closest('[data-passkey-verify]');

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    const error = button.closest('[data-passkey-verification]')?.querySelector('[data-passkey-error]');

    if (!(error instanceof HTMLElement)) {
        return;
    }

    button.disabled = true;
    button.textContent = button.dataset.loadingLabel ?? '';
    error.textContent = '';

    try {
        const response = await Passkeys.verify({
            routes: {
                options: button.dataset.optionsUrl,
                submit: button.dataset.submitUrl,
            },
        });

        if (response.redirect) {
            window.location.assign(response.redirect);
        } else {
            window.location.reload();
        }
    } catch (exception) {
        error.textContent = exception instanceof Error
            ? exception.message
            : 'Passkey sign-in failed. Please try again.';
    } finally {
        button.disabled = false;
        button.textContent = button.dataset.label ?? '';
    }
});