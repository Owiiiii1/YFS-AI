import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { router } from '@inertiajs/react';
import { Bot, Clock3, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function BotSettingsModal({
    open,
    onOpenChange,
    reenableDays = 3,
}) {
    const [days, setDays] = useState(reenableDays);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (open) {
            setDays(reenableDays);
            setError('');
        }
    }, [open, reenableDays]);

    const saveDays = () => {
        setSaving(true);
        setError('');
        router.post(route('bot-management.settings.update'), {
            manual_mode_reenable_days: Number(days),
        }, {
            preserveScroll: true,
            onError: (errors) => setError(errors.manual_mode_reenable_days ?? 'Не удалось сохранить настройки.'),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-lg">
                        <Bot className="h-5 w-5 text-violet-600" />
                        Настройки бота
                    </DialogTitle>
                    <DialogDescription>
                        Операционные параметры автоматизации. Анкеты в Direct не используются — только ссылки на формы.
                    </DialogDescription>
                </DialogHeader>

                <section className="rounded-xl border border-slate-200 p-4">
                    <div className="flex items-start gap-3">
                        <span className="rounded-lg bg-violet-100 p-2 text-violet-700">
                            <Clock3 className="h-5 w-5" />
                        </span>
                        <div>
                            <h3 className="font-semibold text-slate-900">
                                Автовключение после ручного режима
                            </h3>
                            <p className="mt-1 text-sm text-slate-600">
                                Если оператор отключил бота в диалоге и после последней активности прошло указанное время,
                                бот автоматически включится обратно. Самостоятельно сообщение клиенту он не отправляет.
                            </p>
                        </div>
                    </div>

                    <label className="mt-4 block">
                        <span className="text-sm font-medium text-slate-700">
                            Время без активности, дней
                        </span>
                        <input
                            type="number"
                            min="1"
                            max="365"
                            step="1"
                            value={days}
                            onChange={(event) => setDays(event.target.value)}
                            className="admin-input mt-2 max-w-48"
                        />
                    </label>
                    {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
                    <div className="mt-3 flex justify-end">
                        <button
                            type="button"
                            onClick={saveDays}
                            disabled={saving || Number(days) < 1 || Number(days) > 365}
                            className="admin-btn-secondary disabled:opacity-60"
                        >
                            {saving && <Loader2 className="h-4 w-4 animate-spin" />}
                            Сохранить дни
                        </button>
                    </div>
                </section>
            </DialogContent>
        </Dialog>
    );
}
