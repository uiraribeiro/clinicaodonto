/**
 * Odonto Scheduler — JavaScript principal
 * Alpine.js stores e utilitários globais
 */

// ── CSRF helper para requisições fetch/AJAX ────────────────────────────────

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function apiFetch(url, options = {}) {
    const defaults = {
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': getCsrfToken(),
        },
    };
    const response = await fetch(url, { ...defaults, ...options });
    if (!response.ok) {
        const err = await response.json().catch(() => ({ error: 'Erro desconhecido' }));
        throw new Error(err.error ?? `HTTP ${response.status}`);
    }
    return response.json();
}

// ── Alpine.js store global ────────────────────────────────────────────────

document.addEventListener('alpine:init', () => {

    Alpine.store('notif', {
        items: [],
        add(msg, type = 'info') {
            const id = Date.now();
            this.items.push({ id, msg, type });
            setTimeout(() => this.remove(id), 5000);
        },
        remove(id) {
            this.items = this.items.filter(n => n.id !== id);
        },
        success(msg) { this.add(msg, 'success'); },
        error(msg)   { this.add(msg, 'danger');  },
        info(msg)    { this.add(msg, 'info');    },
    });

    Alpine.store('ui', {
        loading: false,
        setLoading(v) { this.loading = v; },
    });

});

// ── Sidebar mobile ───────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');

    // Fecha sidebar ao clicar fora em mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth < 768 && sidebar) {
            if (!sidebar.contains(e.target) && !e.target.closest('[\\@click*="sidebarOpen"]')) {
                sidebar.classList.remove('mobile-open');
            }
        }
    });

    // Confirma ações destrutivas
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Tooltip Bootstrap
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });
});

// ── Formatadores ────────────────────────────────────────────────────────

function formatHora(hora) {
    return hora ? hora.slice(0, 5) : '—';
}

function formatData(data) {
    if (!data) return '—';
    const [y, m, d] = data.split('-');
    return `${d}/${m}/${y}`;
}

function percentualCor(pct) {
    if (pct >= 80) return 'success';
    if (pct >= 50) return 'warning';
    return 'danger';
}
