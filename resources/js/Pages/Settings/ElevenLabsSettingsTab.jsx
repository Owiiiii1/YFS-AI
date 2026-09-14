import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function statusClass(status) {
    if (status === 'connected') return 'bg-emerald-100 text-emerald-800';
    if (status === 'failed') return 'bg-red-100 text-red-700';
    return 'bg-slate-100 text-slate-700';
}

export default function ElevenLabsSettingsTab({ elevenlabs = {} }) {
    const { flash = {}, errors = {}, locale = 'en' } = usePage().props;
    const [apiKey, setApiKey] = useState('');
    const [busy, setBusy] = useState(false);

    const t = {
        en: {
            title: 'ElevenLabs',
            note: 'Store the ElevenLabs API key here. Voice Runtime reads it from Laravel. Speech Engine setup comes later.',
            apiKey: 'API Key',
            save: 'Save',
            connectionStatus: 'Connection Status',
            notConfigured: 'Not configured',
            connected: 'Connected',
            failed: 'Connection failed',
            savedMask: 'Saved key',
        },
        ru: {
            title: 'ElevenLabs',
            note: 'Ключ ElevenLabs хранится здесь. Voice Runtime берёт его из Laravel. Speech Engine настроим отдельно.',
            apiKey: 'API Key',
            save: 'Сохранить',
            connectionStatus: 'Connection Status',
            notConfigured: 'Not configured',
            connected: 'Connected',
            failed: 'Connection failed',
            savedMask: 'Сохранённый ключ',
        },
        uk: {
            title: 'ElevenLabs',
            note: 'Ключ ElevenLabs зберігається тут. Voice Runtime читає його з Laravel. Speech Engine налаштуємо окремо.',
            apiKey: 'API Key',
            save: 'Зберегти',
            connectionStatus: 'Connection Status',
            notConfigured: 'Not configured',
            connected: 'Connected',
            failed: 'Connection failed',
            savedMask: 'Збережений ключ',
        },
    }[locale] ?? {
        title: 'ElevenLabs',
        note: 'Store the ElevenLabs API key here. Voice Runtime reads it from Laravel. Speech Engine setup comes later.',
        apiKey: 'API Key',
        save: 'Save',
        connectionStatus: 'Connection Status',
        notConfigured: 'Not configured',
        connected: 'Connected',
        failed: 'Connection failed',
        savedMask: 'Saved key',
    };

    const statusLabel = {
        connected: t.connected,
        failed: t.failed,
        not_configured: t.notConfigured,
    }[elevenlabs.status] ?? t.notConfigured;

    const save = () => {
        setBusy(true);
        router.post(route('elevenlabs.save'), { api_key: apiKey }, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                setApiKey('');
            },
        });
    };

    return (
        <div className="space-y-6">
            {flash.elevenlabs_status && (
                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {flash.elevenlabs_status}
                </div>
            )}

            <div className="app-widget p-4">
                <h2 className="admin-section-title">{t.title}</h2>
                <p className="mt-1 text-sm text-slate-600">{t.note}</p>

                <div className="mt-4">
                    <p className="mb-2 text-sm font-medium text-slate-600">{t.connectionStatus}</p>
                    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(elevenlabs.status)}`}>
                        {statusLabel}
                    </span>
                </div>

                <label className="mt-4 mb-1 block text-sm font-medium text-slate-600">{t.apiKey}</label>
                <input
                    type="password"
                    value={apiKey}
                    onChange={(e) => setApiKey(e.target.value)}
                    className="admin-input"
                    placeholder={elevenlabs.has_api_key ? '••••••••' : ''}
                    autoComplete="new-password"
                />
                {elevenlabs.api_key_masked && (
                    <p className="mt-1 text-xs text-slate-500">
                        {t.savedMask}: <span className="font-medium">{elevenlabs.api_key_masked}</span>
                    </p>
                )}

                <div className="mt-4">
                    <button
                        type="button"
                        className="admin-btn-primary disabled:opacity-60"
                        disabled={busy}
                        onClick={save}
                    >
                        {t.save}
                    </button>
                </div>

                {errors.elevenlabs && <p className="mt-3 text-sm text-red-600">{errors.elevenlabs}</p>}
                {!errors.elevenlabs && elevenlabs.last_error && elevenlabs.status === 'failed' && (
                    <p className="mt-3 text-sm text-red-600">{elevenlabs.last_error}</p>
                )}
            </div>
        </div>
    );
}
