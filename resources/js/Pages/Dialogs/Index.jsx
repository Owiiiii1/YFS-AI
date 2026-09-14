import ChatBubble from '@/Components/ChatBubble';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { Bot, ImagePlus, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function statusLabel(status, t) {
    if (status === 'pending_human') return t.requiresOperator;
    if (status === 'awaiting_payment') return t.awaitingPayment;
    if (status === 'order_in_progress') return t.orderInProgress;
    if (status === 'closed') return t.closed;
    return t.open;
}

function botStatusBadge(botEnabled, t) {
    if (botEnabled) {
        return <span className="admin-badge-bot-on">{t.botOn}</span>;
    }

    return <span className="admin-badge-bot-off">{t.botOff}</span>;
}

export default function DialogsIndex({
    channel = 'instagram',
    conversations = [],
    activeConversation = null,
    messages = [],
}) {
    const { locale = 'en' } = usePage().props;
    const dialogsRoute = channel === 'facebook' ? 'dialogs.facebook' : 'dialogs.instagram';
    const [modalOpen, setModalOpen] = useState(!!activeConversation);
    const messagesEndRef = useRef(null);
    const messagesContainerRef = useRef(null);
    const previousMessageCountRef = useRef(messages.length);
    const fileInputRef = useRef(null);

    const { start: startListPolling, stop: stopListPolling } = usePoll(
        3000,
        () => ({
            only: ['conversations', 'instagramDialogsUnreadCount', 'facebookDialogsUnreadCount', 'dialogsUnreadCount'],
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
        }),
        { autoStart: true, keepAlive: true },
    );

    const { start: startChatPolling, stop: stopChatPolling } = usePoll(
        1000,
        () => ({
            only: ['messages', 'conversations', 'activeConversation', 'instagramDialogsUnreadCount', 'facebookDialogsUnreadCount', 'dialogsUnreadCount'],
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
        }),
        { autoStart: false, keepAlive: true },
    );

    const text = {
        en: {
            title: 'Dialogs',
            participant: 'Participant',
            messages: 'Messages',
            unread: 'Unread',
            status: 'Status',
            bot: 'Bot',
            botOn: 'Bot on',
            botOff: 'Bot off',
            botToggle: 'Toggle bot for this dialog',
            requiresOperator: 'Operator required',
            awaitingPayment: 'Awaiting payment',
            orderInProgress: 'Order in progress',
            open: 'Open',
            closed: 'Closed',
            empty: 'No dialogs yet.',
            typeMessage: 'Type a message…',
            attachImage: 'Attach image',
            send: 'Send',
            you: 'You',
            customer: 'Customer',
        },
        ru: {
            title: 'Диалоги',
            participant: 'Собеседник',
            messages: 'Сообщений',
            unread: 'Новых',
            status: 'Статус',
            bot: 'Бот',
            botOn: 'Бот вкл',
            botOff: 'Бот выкл',
            botToggle: 'Вкл/выкл бота в этом диалоге',
            requiresOperator: 'Требуется оператор',
            awaitingPayment: 'Ожидает оплаты',
            orderInProgress: 'Заказ в работе',
            open: 'Открыт',
            closed: 'Закрыт',
            empty: 'Диалогов пока нет.',
            typeMessage: 'Введите сообщение…',
            attachImage: 'Прикрепить фото',
            send: 'Отправить',
            you: 'Вы',
            customer: 'Клиент',
        },
        uk: {
            title: 'Діалоги',
            participant: 'Співрозмовник',
            messages: 'Повідомлень',
            unread: 'Нових',
            status: 'Статус',
            bot: 'Бот',
            botOn: 'Бот увімк',
            botOff: 'Бот вимк',
            botToggle: 'Увімк/вимк бота в цьому діалозі',
            requiresOperator: 'Потрібен оператор',
            awaitingPayment: 'Очікує оплату',
            orderInProgress: 'Замовлення в роботі',
            open: 'Відкритий',
            closed: 'Закритий',
            empty: 'Діалогів поки що немає.',
            typeMessage: 'Введіть повідомлення…',
            attachImage: 'Додати фото',
            send: 'Надіслати',
            you: 'Ви',
            customer: 'Клієнт',
        },
    };
    const t = text[locale] ?? text.en;

    const form = useForm({
        body: '',
        attachment: null,
    });

    const botForm = useForm({
        bot_enabled: activeConversation?.bot_enabled ?? true,
    });

    useEffect(() => {
        botForm.setData('bot_enabled', activeConversation?.bot_enabled ?? true);
    }, [activeConversation?.bot_enabled]);

    useEffect(() => {
        setModalOpen(!!activeConversation);
    }, [activeConversation?.id]);

    useEffect(() => {
        if (modalOpen && activeConversation) {
            stopListPolling();
            startChatPolling();

            return () => {
                stopChatPolling();
                startListPolling();
            };
        }

        stopChatPolling();
        startListPolling();
    }, [modalOpen, activeConversation?.id, startChatPolling, stopChatPolling, startListPolling, stopListPolling]);

    useEffect(() => {
        if (!modalOpen || !activeConversation) {
            return;
        }

        previousMessageCountRef.current = 0;
        requestAnimationFrame(() => {
            messagesEndRef.current?.scrollIntoView({ behavior: 'auto' });
        });
    }, [activeConversation?.id, modalOpen]);

    useEffect(() => {
        if (!modalOpen) {
            previousMessageCountRef.current = messages.length;
            return;
        }

        const previousCount = previousMessageCountRef.current;
        const hasNewMessages = messages.length > previousCount;
        previousMessageCountRef.current = messages.length;

        if (!hasNewMessages) {
            return;
        }

        const container = messagesContainerRef.current;
        const isNearBottom = container
            ? container.scrollHeight - container.scrollTop - container.clientHeight < 96
            : true;

        if (isNearBottom) {
            messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
        }
    }, [messages, modalOpen]);

    const openConversation = (conversationId) => {
        router.get(
            route(dialogsRoute, { conversation: conversationId }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const closeModal = () => {
        setModalOpen(false);
        router.get(route(dialogsRoute), {}, { preserveState: true, preserveScroll: true });
    };

    const toggleBot = () => {
        if (!activeConversation) return;

        const next = !botForm.data.bot_enabled;
        botForm.setData('bot_enabled', next);
        botForm.patch(route('dialogs.bot.update', activeConversation.id), {
            preserveScroll: true,
        });
    };

    const submitMessage = (e) => {
        e.preventDefault();
        if (!activeConversation) return;

        form.post(route('dialogs.messages.store', activeConversation.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset('body', 'attachment');
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />

            <div className="admin-table-wrap">
                <table className="min-w-full text-sm">
                    <thead className="text-xs uppercase tracking-wide">
                        <tr>
                            <th className="px-4 py-3 text-left font-semibold">{t.participant}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.messages}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.unread}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.bot}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.status}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {conversations.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-[var(--admin-on-surface-variant)]">
                                    {t.empty}
                                </td>
                            </tr>
                        ) : (
                            conversations.map((conversation) => {
                                const isActive = activeConversation?.id === conversation.id;

                                return (
                                <tr
                                    key={conversation.id}
                                    onClick={() => openConversation(conversation.id)}
                                    className={`cursor-pointer transition hover:bg-[var(--admin-surface-container-low)] ${
                                        isActive ? 'admin-chat-row-active' : ''
                                    } ${
                                        conversation.status === 'pending_human'
                                            ? 'admin-chat-row-pending-human'
                                            : ''
                                    }`}
                                >
                                    <td className="px-4 py-3">
                                        <p className="font-medium text-[var(--admin-primary)]">{conversation.display_name}</p>
                                        {conversation.participant_username && (
                                            <p className="text-xs text-[var(--admin-on-surface-variant)]">@{conversation.participant_username}</p>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-[var(--admin-on-surface)]">{conversation.messages_count}</td>
                                    <td className="px-4 py-3">
                                        {conversation.unread_count > 0 ? (
                                            <span className="admin-badge-unread">
                                                {conversation.unread_count}
                                            </span>
                                        ) : (
                                            <span className="text-[var(--admin-on-surface-variant)]">0</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {botStatusBadge(conversation.bot_enabled, t)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {conversation.requires_operator ? (
                                            <span className="admin-badge-operator">
                                                {t.requiresOperator}
                                            </span>
                                        ) : (
                                            <span className={`admin-badge-status admin-badge-status--${conversation.status}`}>
                                                {statusLabel(conversation.status, t)}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            <Dialog open={modalOpen} onOpenChange={(open) => !open && closeModal()}>
                <DialogContent className="admin-theme admin-chat-dialog flex h-[min(82vh,760px)] max-w-3xl flex-col gap-0 overflow-hidden rounded-2xl border border-[var(--admin-outline-variant)] bg-[var(--admin-surface-bright)] p-0 shadow-[var(--admin-shadow)] ring-0 sm:max-w-3xl">
                    <DialogHeader className="border-b border-[var(--admin-outline-variant)] bg-[var(--admin-surface-bright)] px-5 py-4 text-left">
                        <div className="flex flex-wrap items-start justify-between gap-3 pr-8">
                            <div>
                                <DialogTitle className="font-display text-lg font-semibold text-[var(--admin-primary)]">
                                    {activeConversation?.display_name ?? t.title}
                                </DialogTitle>
                                {activeConversation?.participant_username && (
                                    <p className="mt-1 text-sm text-[var(--admin-on-surface-variant)]">
                                        @{activeConversation.participant_username}
                                    </p>
                                )}
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {activeConversation && botStatusBadge(activeConversation.bot_enabled, t)}
                                    {activeConversation?.requires_operator && (
                                        <span className="admin-badge-operator">
                                            {t.requiresOperator}
                                        </span>
                                    )}
                                    {activeConversation && !activeConversation.requires_operator && (
                                        <span className={`admin-badge-status admin-badge-status--${activeConversation.status}`}>
                                            {statusLabel(activeConversation.status, t)}
                                        </span>
                                    )}
                                </div>
                            </div>
                            {activeConversation && (
                                <button
                                    type="button"
                                    onClick={toggleBot}
                                    disabled={botForm.processing}
                                    title={t.botToggle}
                                    className={`admin-chat-bot-toggle ${
                                        botForm.data.bot_enabled
                                            ? 'admin-chat-bot-toggle--on'
                                            : 'admin-chat-bot-toggle--off'
                                    }`}
                                >
                                    <Bot className="h-4 w-4" />
                                    {botForm.data.bot_enabled ? t.botOn : t.botOff}
                                </button>
                            )}
                        </div>
                    </DialogHeader>

                    <div
                        ref={messagesContainerRef}
                        className="admin-chat-messages flex-1 space-y-3 overflow-y-auto px-4 py-4"
                    >
                        {messages.map((message) => (
                            <ChatBubble
                                key={message.id}
                                message={message}
                                isMine={message.direction === 'outbound'}
                                locale={locale}
                            />
                        ))}
                        <div ref={messagesEndRef} />
                    </div>

                    <form
                        onSubmit={submitMessage}
                        className="admin-chat-composer px-4 py-3"
                    >
                        {form.data.attachment && (
                            <p className="mb-2 truncate text-xs text-[var(--admin-on-surface-variant)]">
                                {form.data.attachment.name}
                            </p>
                        )}
                        {form.errors.message && (
                            <p className="mb-2 text-sm text-red-600">{form.errors.message}</p>
                        )}
                        <div className="flex items-end gap-2">
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-[var(--admin-outline-variant)] text-[var(--admin-on-surface-variant)] transition hover:bg-[var(--admin-surface-container-low)]"
                                title={t.attachImage}
                            >
                                <ImagePlus className="h-4 w-4" />
                            </button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)}
                            />
                            <textarea
                                rows={2}
                                value={form.data.body}
                                onChange={(e) => form.setData('body', e.target.value)}
                                placeholder={t.typeMessage}
                                className="admin-input min-h-10 flex-1 resize-none py-2"
                            />
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="admin-btn-primary disabled:opacity-60"
                            >
                                <Send className="h-4 w-4" />
                                {t.send}
                            </button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
