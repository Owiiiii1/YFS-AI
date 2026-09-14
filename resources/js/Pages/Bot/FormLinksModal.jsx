import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { router } from '@inertiajs/react';
import { Link2, Loader2, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

const copy = {
    en: {
        title: 'Application forms',
        description: 'The bot sends these exact URLs. Change a link here and the next replies use it.',
        name: 'Name',
        url: 'URL',
        add: 'Add link',
        save: 'Save links',
        saving: 'Saving…',
        empty: 'Add at least one form link.',
    },
    ru: {
        title: 'Ссылки на анкеты',
        description: 'Бот отправляет именно эти URL. Изменение в таблице сразу действует на следующие ответы.',
        name: 'Название',
        url: 'Ссылка',
        add: 'Добавить ссылку',
        save: 'Сохранить ссылки',
        saving: 'Сохраняем…',
        empty: 'Добавьте хотя бы одну ссылку.',
    },
    uk: {
        title: 'Посилання на анкети',
        description: 'Бот надсилає саме ці URL. Зміна в таблиці одразу діє на наступні відповіді.',
        name: 'Назва',
        url: 'Посилання',
        add: 'Додати посилання',
        save: 'Зберегти посилання',
        saving: 'Зберігаємо…',
        empty: 'Додайте хоча б одне посилання.',
    },
};

function blankLink() {
    return {
        id: `form_${Math.random().toString(36).slice(2, 8)}`,
        labels: { en: '', ru: '', uk: '' },
        url: '',
    };
}

export default function FormLinksModal({ open, onOpenChange, links = [], locale = 'en' }) {
    const t = copy[locale] ?? copy.ru;
    const [rows, setRows] = useState(links);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (open) {
            setRows(links.length > 0 ? links : [blankLink()]);
            setError('');
        }
    }, [open, links]);

    const updateRow = (index, patch) => {
        setRows((current) => current.map((row, rowIndex) => (
            rowIndex === index ? { ...row, ...patch } : row
        )));
    };

    const save = () => {
        setSaving(true);
        setError('');
        router.post(route('bot-management.form-links.update'), {
            form_links: rows.map((row) => ({
                id: row.id,
                url: row.url,
                labels: {
                    en: row.labels?.en || row.labels?.ru || row.id,
                    ru: row.labels?.ru || row.labels?.en || row.id,
                    uk: row.labels?.uk || row.labels?.ru || row.labels?.en || row.id,
                },
            })),
        }, {
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors).flat()[0] ?? t.empty),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-lg">
                        <Link2 className="h-5 w-5 text-violet-600" />
                        {t.title}
                    </DialogTitle>
                    <DialogDescription>{t.description}</DialogDescription>
                </DialogHeader>

                <div className="overflow-x-auto rounded-xl border border-slate-200">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-slate-500">
                            <tr>
                                <th className="px-4 py-3 font-medium">{t.name}</th>
                                <th className="px-4 py-3 font-medium">{t.url}</th>
                                <th className="w-12 px-3 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => (
                                <tr key={row.id} className="border-t border-slate-100">
                                    <td className="px-4 py-2">
                                        <input
                                            value={row.labels?.[locale] ?? row.labels?.ru ?? row.labels?.en ?? ''}
                                            onChange={(event) => updateRow(index, {
                                                labels: { ...row.labels, [locale]: event.target.value, en: row.labels?.en || event.target.value },
                                            })}
                                            className="admin-input"
                                        />
                                    </td>
                                    <td className="px-4 py-2">
                                        <input
                                            value={row.url}
                                            onChange={(event) => updateRow(index, { url: event.target.value })}
                                            className="admin-input font-mono text-xs"
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        <button
                                            type="button"
                                            className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-rose-600"
                                            onClick={() => setRows((current) => current.filter((_, rowIndex) => rowIndex !== index))}
                                            disabled={rows.length <= 1}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {error && <p className="text-sm text-rose-600">{error}</p>}

                <div className="flex flex-wrap justify-between gap-2">
                    <button type="button" className="admin-btn-secondary" onClick={() => setRows((current) => [...current, blankLink()])}>
                        <Plus className="h-4 w-4" />
                        {t.add}
                    </button>
                    <button type="button" className="admin-btn-primary" onClick={save} disabled={saving}>
                        {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Link2 className="h-4 w-4" />}
                        {saving ? t.saving : t.save}
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
