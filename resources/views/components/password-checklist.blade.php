@props(['for' => 'password', 'confirm' => null])

{{--
    Live password checklist. Updates while the person types, no page reload,
    and never clears what they typed. The server still validates on submit.
--}}
<div
    x-data="{
        value: '',
        confirmValue: '',
        rules: [
            { label: 'At least 12 characters', test: (v) => v.length >= 12 },
            { label: 'An uppercase letter (A-Z)', test: (v) => /[A-Z]/.test(v) },
            { label: 'A lowercase letter (a-z)', test: (v) => /[a-z]/.test(v) },
            { label: 'A number (0-9)', test: (v) => /[0-9]/.test(v) },
            { label: 'A special character (like ! ? # $ %)', test: (v) => /[^A-Za-z0-9]/.test(v) },
        ],
        get passed() { return this.rules.filter((rule) => rule.test(this.value)).length },
        get percent() { return Math.round((this.passed / this.rules.length) * 100) },
        get level() {
            if (this.value.length === 0) return 'empty'
            if (this.passed === this.rules.length) return 'strong'
            if (this.passed >= 3) return 'fair'
            return 'weak'
        },
        get levelLabel() {
            return { empty: 'Start typing your password', weak: 'Weak', fair: 'Almost there', strong: 'Strong password' }[this.level]
        },
        init() {
            const bind = (id, key) => {
                const el = id ? document.getElementById(id) : null
                if (!el) return
                this[key] = el.value
                el.addEventListener('input', () => { this[key] = el.value })
            }
            bind(@js($for), 'value')
            bind(@js($confirm), 'confirmValue')
        },
    }"
    class="grid gap-3 rounded-xl border border-[var(--ps-border)] bg-[var(--ps-surface)] p-4"
    aria-live="polite"
>
    <div class="grid gap-1.5">
        <div class="flex items-center justify-between text-xs font-semibold">
            <span x-text="levelLabel"
                  :class="{
                      'text-[var(--ps-muted)]': level === 'empty',
                      'text-red-500': level === 'weak',
                      'text-amber-500': level === 'fair',
                      'text-emerald-500': level === 'strong',
                  }"></span>
            <span class="tabular-nums text-[var(--ps-muted)]" x-text="passed + ' of ' + rules.length"></span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-[var(--ps-border)]">
            <div class="h-full rounded-full transition-all duration-300"
                 :style="'width: ' + percent + '%'"
                 :class="{
                     'bg-red-500': level === 'weak',
                     'bg-amber-500': level === 'fair',
                     'bg-emerald-500': level === 'strong',
                 }"></div>
        </div>
    </div>

    <ul class="grid gap-1.5 text-sm">
        <template x-for="rule in rules" :key="rule.label">
            <li class="flex items-center gap-2"
                :class="rule.test(value) ? 'text-emerald-500' : 'text-[var(--ps-muted)]'">
                <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                      :class="rule.test(value) ? 'bg-emerald-500/15' : 'bg-red-500/15 text-red-500'"
                      x-text="rule.test(value) ? '✓' : '✕'"
                      aria-hidden="true"></span>
                <span x-text="rule.label"></span>
                <span class="sr-only" x-text="rule.test(value) ? '(done)' : '(missing)'"></span>
            </li>
        </template>

        @if ($confirm)
            <li class="flex items-center gap-2"
                :class="(confirmValue.length > 0 && confirmValue === value) ? 'text-emerald-500' : 'text-[var(--ps-muted)]'">
                <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                      :class="(confirmValue.length > 0 && confirmValue === value) ? 'bg-emerald-500/15' : 'bg-red-500/15 text-red-500'"
                      x-text="(confirmValue.length > 0 && confirmValue === value) ? '✓' : '✕'"
                      aria-hidden="true"></span>
                <span>Both passwords match</span>
            </li>
        @endif
    </ul>
</div>