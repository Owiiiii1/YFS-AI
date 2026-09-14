import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import axios from 'axios';
import { AlertTriangle, ArrowRight, Bot, CheckCircle2, History, Loader2, RotateCcw, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const stepLabels = ['Вопрос', 'Анализ', 'Исправление', 'Preview', 'Подтверждение', 'Применение', 'Далее'];
const nextActionClass = 'admin-btn-primary min-h-11 justify-center bg-violet-600 px-5 font-semibold text-white shadow-lg shadow-violet-200 ring-2 ring-violet-200 hover:bg-violet-700 disabled:opacity-60';

export default function AiAnalysisWorkflowModal({ open, onOpenChange, ready = false }) {
    const [session, setSession] = useState(null);
    const [question, setQuestion] = useState('');
    const [instruction, setInstruction] = useState('');
    const [parentId, setParentId] = useState(null);
    const [dialogQuery, setDialogQuery] = useState('');
    const [dialogOptions, setDialogOptions] = useState([]);
    const [selectedDialog, setSelectedDialog] = useState(null);
    const [busy, setBusy] = useState(false);
    const [busyLabel, setBusyLabel] = useState('');
    const [error, setError] = useState('');
    const [historyOpen, setHistoryOpen] = useState(false);
    const [historyItems, setHistoryItems] = useState([]);

    const currentStep = session?.step ?? 1;
    const proposal = session?.proposal;
    const sensitive = proposal?.sensitive_changes ?? [];
    const proposalValid = proposal?.validation?.valid !== false;

    useEffect(() => {
        if (!open || !ready) return;
        setBusy(true);
        axios.get(route('bot-management.ai-analysis.active'))
            .then(({ data }) => {
                if (data.session) {
                    setSession(data.session);
                    setInstruction(data.session.instruction ?? '');
                }
            })
            .catch((exception) => setError(apiError(exception)))
            .finally(() => setBusy(false));
    }, [open, ready]);

    const options = useMemo(() => session?.analysis?.options ?? [], [session]);

    const start = async () => {
        if (!question.trim()) return;
        await execute(async () => {
            const created = await axios.post(route('bot-management.ai-analysis.store'), {
                question,
                parent_session_id: parentId,
                conversation_id: selectedDialog?.id ?? null,
            });
            setSession(created.data.session);
            const analyzed = await axios.post(route('bot-management.ai-analysis.analyze', created.data.session.id));
            setSession(analyzed.data.session);
        }, 'AI изучает диалог, промпт и технические трассировки…');
    };

    const buildPreview = async () => {
        if (!instruction.trim() || !session) return;
        await execute(async () => {
            const updated = await axios.post(
                route('bot-management.ai-analysis.instruction', session.id),
                { instruction },
            );
            setSession(updated.data.session);
            const previewed = await axios.post(route('bot-management.ai-analysis.preview', session.id));
            setSession(previewed.data.session);
        }, 'AI формирует безопасное изменение и проверяет результат…');
    };

    const approve = () => execute(async () => {
        const { data } = await axios.post(route('bot-management.ai-analysis.approve', session.id));
        setSession(data.session);
    }, 'Сохраняем подтверждение…');

    const apply = () => execute(async () => {
        const { data } = await axios.post(route('bot-management.ai-analysis.apply', session.id));
        setSession(data.session);
    }, 'Атомарно применяем новую ревизию промпта…');

    const finish = () => execute(async () => {
        await axios.post(route('bot-management.ai-analysis.finish', session.id));
        setSession(null);
        setParentId(null);
        setQuestion('');
        setInstruction('');
        setDialogQuery('');
        setDialogOptions([]);
        setSelectedDialog(null);
        onOpenChange(false);
    });

    const nextQuestion = (continueContext) => {
        setParentId(continueContext ? session.id : null);
        setSession(null);
        setQuestion('');
        setInstruction('');
        setDialogQuery('');
        setDialogOptions([]);
        setSelectedDialog(null);
        setError('');
    };

    const execute = async (callback, label = 'Выполняется запрос…') => {
        setBusy(true);
        setBusyLabel(label);
        setError('');
        try {
            await callback();
        } catch (exception) {
            if (exception.response?.data?.session) setSession(exception.response.data.session);
            setError(apiError(exception));
        } finally {
            setBusy(false);
            setBusyLabel('');
        }
    };

    const toggleHistory = () => {
        if (historyOpen) {
            setHistoryOpen(false);
            return;
        }

        execute(async () => {
            const { data } = await axios.get(route('bot-management.ai-analysis.history'));
            setHistoryItems(data.sessions ?? []);
            setHistoryOpen(true);
        }, 'Загружаем историю анализов…');
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2 text-lg">
                        <Sparkles className="h-5 w-5 text-violet-600" />
                        AI-анализ ответов и промптов
                    </DialogTitle>
                    <DialogDescription>
                        Анализ опирается на диалог и трассировку. Изменение применяется только после отдельного подтверждения.
                    </DialogDescription>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={toggleHistory} disabled={busy} className="admin-btn-secondary">
                            <History className="h-4 w-4" />
                            {historyOpen ? 'Скрыть историю' : 'История анализов'}
                        </button>
                        {session && session.status !== 'finished' && (
                            <button
                                type="button"
                                onClick={finish}
                                disabled={busy}
                                className="admin-btn-secondary border-red-200 text-red-700 hover:bg-red-50"
                            >
                                Завершить анализ
                            </button>
                        )}
                    </div>
                </DialogHeader>

                {busy && (
                    <div className="flex items-center gap-4 rounded-xl border border-violet-200 bg-violet-50 p-4 text-violet-900">
                        <span className="relative flex h-10 w-10 shrink-0 items-center justify-center">
                            <span className="absolute h-10 w-10 animate-spin rounded-full border-2 border-violet-200 border-t-violet-600" />
                            <Sparkles className="h-5 w-5 animate-pulse text-violet-700" />
                        </span>
                        <div>
                            <div className="font-semibold">AI-анализатор работает</div>
                            <div className="mt-0.5 text-sm text-violet-700">{busyLabel}</div>
                        </div>
                    </div>
                )}

                {historyOpen && (
                    <section className="max-h-64 space-y-2 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3">
                        {historyItems.length === 0 && (
                            <p className="text-sm text-slate-500">Сохранённых анализов пока нет.</p>
                        )}
                        {historyItems.map((item) => (
                            <button
                                key={item.id}
                                type="button"
                                className="block w-full rounded-lg border border-slate-200 bg-white p-3 text-left hover:border-violet-300"
                                onClick={() => execute(async () => {
                                    const { data } = await axios.get(
                                        route('bot-management.ai-analysis.show', item.id),
                                    );
                                    setSession(data.session);
                                    setInstruction(data.session.instruction ?? '');
                                    setHistoryOpen(false);
                                }, 'Открываем сохранённый анализ…')}
                            >
                                <span className="block truncate text-sm font-semibold text-slate-800">{item.question}</span>
                                <span className="mt-1 block text-xs text-slate-500">
                                    #{item.id} · {item.status}
                                    {item.cause_category ? ` · ${item.cause_category}` : ''}
                                    {item.conversation ? ` · @${item.conversation.username}` : ''}
                                    {' · '}
                                    {new Date(item.updated_at).toLocaleString()}
                                </span>
                            </button>
                        ))}
                    </section>
                )}

                <div className="grid grid-cols-7 gap-1">
                    {stepLabels.map((label, index) => {
                        const number = index + 1;
                        return (
                            <div key={label} className="text-center">
                                <div className={`mx-auto flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold ${
                                    currentStep >= number ? 'bg-violet-600 text-white' : 'bg-slate-100 text-slate-500'
                                }`}>
                                    {number}
                                </div>
                                <div className="mt-1 hidden text-[11px] text-slate-500 sm:block">{label}</div>
                            </div>
                        );
                    })}
                </div>

                {!ready && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-900">
                        Сначала подключите назначение «Управление AI-анализом» в AI-настройках.
                    </div>
                )}

                {error && (
                    <div className="flex gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                {ready && !session && (
                    <section className="space-y-3">
                        {parentId && (
                            <p className="rounded-lg bg-violet-50 p-3 text-sm text-violet-800">
                                Продолжение текущего анализа: предыдущий контекст будет учтён.
                            </p>
                        )}
                        <label className="block text-sm font-medium text-slate-700">В чём вопрос?</label>
                        <textarea
                            value={question}
                            onChange={(event) => setQuestion(event.target.value)}
                            rows={6}
                            className="admin-input"
                            placeholder="Например: почему в диалоге с @username бот ответил на другом языке?"
                        />
                        <div className="rounded-xl border border-slate-200 p-3">
                            <label className="block text-sm font-medium text-slate-700">
                                Диалог для анализа (необязательно)
                            </label>
                            <div className="mt-2 flex gap-2">
                                <input
                                    value={dialogQuery}
                                    onChange={(event) => setDialogQuery(event.target.value)}
                                    className="admin-input"
                                    placeholder="@username"
                                />
                                <button
                                    type="button"
                                    disabled={busy || !dialogQuery.trim()}
                                    className="admin-btn-secondary shrink-0"
                                    onClick={() => execute(async () => {
                                        const { data } = await axios.get(
                                            route('bot-management.ai-analysis.dialogs'),
                                            { params: { username: dialogQuery } },
                                        );
                                        setDialogOptions(data.conversations ?? []);
                                    })}
                                >
                                    Найти
                                </button>
                            </div>
                            {selectedDialog && (
                                <p className="mt-2 text-sm text-violet-700">
                                    Выбран @{selectedDialog.username} · {selectedDialog.channel}
                                </p>
                            )}
                            {dialogOptions.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {dialogOptions.map((dialog) => (
                                        <button
                                            key={dialog.id}
                                            type="button"
                                            onClick={() => setSelectedDialog(dialog)}
                                            className="rounded-lg border border-slate-200 px-3 py-2 text-left text-xs hover:border-violet-300"
                                        >
                                            @{dialog.username} · {dialog.channel}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={start}
                            disabled={busy || !question.trim()}
                            className={nextActionClass}
                        >
                            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Bot className="h-4 w-4" />}
                            Начать анализ
                            <ArrowRight className="h-4 w-4" />
                        </button>
                    </section>
                )}

                {session && (
                    <div className="space-y-4">
                        <div className="rounded-xl bg-slate-50 p-3 text-sm">
                            <span className="font-semibold">Вопрос:</span> {session.question}
                            {session.conversation && (
                                <div className="mt-1 text-slate-600">
                                    Диалог: @{session.conversation.username} · {session.conversation.channel}
                                </div>
                            )}
                        </div>

                        {session.analysis && (
                            <section className="space-y-3 rounded-xl border border-slate-200 p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="font-semibold text-slate-900">Результат анализа</h3>
                                    <span className="rounded-full bg-slate-100 px-2 py-1 text-xs">
                                        {session.analysis.cause_category} · {Math.round((session.analysis.confidence ?? 0) * 100)}%
                                    </span>
                                </div>
                                <p className="whitespace-pre-wrap text-sm text-slate-700">{session.analysis.summary}</p>
                                {(session.analysis.evidence ?? []).length > 0 && (
                                    <ul className="list-disc space-y-1 pl-5 text-sm text-slate-600">
                                        {session.analysis.evidence.map((item, index) => <li key={index}>{item}</li>)}
                                    </ul>
                                )}
                                {options.length > 0 && (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {options.map((option) => (
                                            <button
                                                key={option.id}
                                                type="button"
                                                onClick={() => setInstruction(option.description)}
                                                className="rounded-lg border border-slate-200 p-3 text-left hover:border-violet-300 hover:bg-violet-50"
                                            >
                                                <span className="block text-sm font-semibold">{option.title}</span>
                                                <span className="mt-1 block text-xs text-slate-600">{option.description}</span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </section>
                        )}

                        {session.status === 'failed' && (
                            <button
                                type="button"
                                onClick={() => execute(async () => {
                                    const { data } = await axios.post(route('bot-management.ai-analysis.analyze', session.id));
                                    setSession(data.session);
                                })}
                                disabled={busy}
                                className="admin-btn-secondary"
                            >
                                <RotateCcw className="h-4 w-4" />
                                Повторить анализ
                            </button>
                        )}

                        {session.status === 'analyzed' && !proposal && (
                            <section className="space-y-3">
                                <label className="block text-sm font-medium text-slate-700">
                                    Что именно нужно исправить?
                                </label>
                                <textarea
                                    value={instruction}
                                    onChange={(event) => setInstruction(event.target.value)}
                                    rows={4}
                                    className="admin-input"
                                    placeholder="Выберите вариант выше или опишите желаемое поведение."
                                />
                                <button
                                    type="button"
                                    onClick={buildPreview}
                                    disabled={busy || !instruction.trim()}
                                    className={nextActionClass}
                                >
                                    {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                                    Подготовить «было / будет»
                                    <ArrowRight className="h-4 w-4" />
                                </button>
                            </section>
                        )}

                        {proposal && (
                            <section className="space-y-3 rounded-xl border border-slate-200 p-4">
                                <h3 className="font-semibold text-slate-900">Предлагаемые изменения</h3>
                                {proposal.validation?.explanation && (
                                    <div className="flex gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4">
                                        <Sparkles className="mt-0.5 h-5 w-5 shrink-0 text-violet-700" />
                                        <div>
                                            <div className="font-semibold text-violet-900">Что AI хочет изменить</div>
                                            <p className="mt-1 whitespace-pre-wrap text-sm text-violet-800">
                                                {proposal.validation.explanation}
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {!proposalValid && (
                                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                                        <div className="font-semibold">Предложение пока нельзя применить</div>
                                        <p className="mt-1">
                                            Вы можете изучить его полностью. Применение заблокировано только до устранения ошибок проверки:
                                        </p>
                                        <ul className="mt-2 list-disc space-y-1 pl-5">
                                            {(proposal.validation?.errors ?? []).map((validationError) => (
                                                <li key={validationError}>{validationError}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                {sensitive.length > 0 && (
                                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                        <strong>Высокорисковые значения:</strong> {sensitive.join(', ')}.
                                        Проверьте влияние на расчёты, оплату и сообщения клиентам.
                                    </div>
                                )}
                                <div className="space-y-3">
                                    {(proposal.diff ?? []).map((item) => (
                                        <div key={item.path} className="overflow-hidden rounded-lg border border-slate-200">
                                            <div className="bg-slate-50 px-3 py-2 font-mono text-xs font-semibold">{item.path}</div>
                                            <div className="grid gap-px bg-slate-200 md:grid-cols-2">
                                                <DiffValue label="Было" value={item.before} tone="red" />
                                                <DiffValue label="Будет" value={item.after} tone="green" />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                {(proposal.validation?.checks ?? []).map((check) => (
                                    <p key={check} className="flex items-center gap-2 text-xs text-emerald-700">
                                        <CheckCircle2 className="h-4 w-4" /> {check}
                                    </p>
                                ))}
                            </section>
                        )}

                        {session.status === 'preview_ready' && proposalValid && (
                            <button type="button" onClick={approve} disabled={busy} className={nextActionClass}>
                                {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                                Подтверждаю это изменение
                                <ArrowRight className="h-4 w-4" />
                            </button>
                        )}

                        {session.status === 'preview_ready' && !proposalValid && (
                            <section className="space-y-3 rounded-xl border border-slate-200 p-4">
                                <label className="block text-sm font-medium text-slate-700">
                                    Уточните пожелание, чтобы AI подготовил новый вариант
                                </label>
                                <textarea
                                    value={instruction}
                                    onChange={(event) => setInstruction(event.target.value)}
                                    rows={3}
                                    className="admin-input"
                                />
                                <button
                                    type="button"
                                    onClick={buildPreview}
                                    disabled={busy || !instruction.trim()}
                                    className={nextActionClass}
                                >
                                    Подготовить новый вариант
                                    <ArrowRight className="h-4 w-4" />
                                </button>
                            </section>
                        )}

                        {session.status === 'approved' && (
                            <div className="rounded-xl border border-violet-200 bg-violet-50 p-4">
                                <p className="mb-3 text-sm text-violet-900">
                                    Подтверждение сохранено. Следующее действие изменит активный prompt_config атомарно.
                                </p>
                                <button type="button" onClick={apply} disabled={busy} className={nextActionClass}>
                                    {busy && <Loader2 className="h-4 w-4 animate-spin" />}
                                    Применить изменение
                                    <ArrowRight className="h-4 w-4" />
                                </button>
                            </div>
                        )}

                        {session.status === 'applied' && (
                            <section className="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                                <h3 className="font-semibold text-emerald-900">Изменение применено</h3>
                                <p className="mt-1 text-sm text-emerald-800">
                                    Активная ревизия: {proposal?.applied_revision}.
                                </p>
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <button type="button" onClick={() => nextQuestion(true)} className={nextActionClass}>
                                        Продолжить текущий
                                        <ArrowRight className="h-4 w-4" />
                                    </button>
                                    <button type="button" onClick={() => nextQuestion(false)} className="admin-btn-secondary">
                                        Открыть новый
                                    </button>
                                    <button type="button" onClick={finish} className="admin-btn-secondary">
                                        Завершить
                                    </button>
                                </div>
                            </section>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function DiffValue({ label, value, tone }) {
    return (
        <div className="bg-white p-3">
            <div className={`mb-1 text-xs font-semibold ${tone === 'red' ? 'text-red-600' : 'text-emerald-700'}`}>
                {label}
            </div>
            <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-words text-xs text-slate-700">
                {typeof value === 'string' ? value : JSON.stringify(value, null, 2)}
            </pre>
        </div>
    );
}

function apiError(exception) {
    const errors = exception.response?.data?.errors;
    if (errors) return Object.values(errors).flat().join(' ');
    return exception.response?.data?.message || exception.message || 'Не удалось выполнить запрос.';
}
