import { formatMessageTime, linkifyText } from '@/lib/chat';

function senderBadgeLabel(message, locale) {
    if (message.direction !== 'outbound') {
        return null;
    }

    if (message.sender_type === 'bot') {
        return { en: 'Bot', ru: 'Бот', uk: 'Бот' }[locale] ?? 'Bot';
    }

    if (message.sent_via === 'instagram') {
        return { en: 'You (Instagram)', ru: 'Вы (Instagram)', uk: 'Ви (Instagram)' }[locale] ?? 'You (Instagram)';
    }

    return { en: 'You (CRM)', ru: 'Вы (CRM)', uk: 'Ви (CRM)' }[locale] ?? 'You (CRM)';
}

export default function ChatBubble({ message, isMine, locale = 'en' }) {
    const hasImage = !!message.attachment_url;
    const hasText = !!message.body?.trim();
    const senderLabel = senderBadgeLabel(message, locale);

    return (
        <div className={`flex ${isMine ? 'justify-end' : 'justify-start'}`}>
            <div
                className={`max-w-[78%] rounded-2xl px-3.5 py-2.5 ${
                    isMine
                        ? message.sender_type === 'bot'
                            ? 'admin-chat-bubble-bot rounded-br-md border border-[#c4b5fd] bg-[#ede9fe] text-[#4c1d95] shadow-[0_2px_8px_rgba(91,33,182,0.08)]'
                            : 'admin-chat-bubble-out rounded-br-md bg-[#5b21b6] text-[#faf5ff] shadow-[0_4px_14px_rgba(91,33,182,0.22)]'
                        : 'admin-chat-bubble-in rounded-bl-md border border-[#d4c4ea] border-l-[3px] border-l-[#f472b6] bg-[#fcfaff] text-[#1a1230] shadow-[0_2px_8px_rgba(91,33,182,0.08)]'
                }`}
            >
                {senderLabel && (
                    <p
                        className={`mb-1 text-[10px] font-semibold uppercase tracking-wide ${
                            message.sender_type === 'bot' ? 'text-[#6d28d9]' : 'text-[#faf5ff]/75'
                        }`}
                    >
                        {senderLabel}
                    </p>
                )}

                {hasImage && (
                    <a href={message.attachment_url} target="_blank" rel="noopener noreferrer" className="block">
                        <img
                            src={message.attachment_url}
                            alt=""
                            className="mb-2 max-h-64 w-full rounded-xl border border-[#d4c4ea]/60 object-cover"
                        />
                    </a>
                )}

                {hasText && (
                    <div
                        className={`whitespace-pre-wrap break-words text-sm leading-relaxed ${
                            isMine
                                ? message.sender_type === 'bot'
                                    ? '[&_a]:text-[#5b21b6]/90'
                                    : '[&_a]:text-[#faf5ff]/90'
                                : '[&_a]:text-[#c026d3] [&_a]:font-medium [&_a]:underline'
                        }`}
                        dangerouslySetInnerHTML={{ __html: linkifyText(message.body) }}
                    />
                )}

                <p
                    className={`mt-1.5 text-[11px] ${
                        isMine
                            ? message.sender_type === 'bot'
                                ? 'text-[#6d28d9]/70'
                                : 'text-[#faf5ff]/65'
                            : 'text-[#5b4d73]'
                    }`}
                >
                    {formatMessageTime(message.sent_at)}
                </p>
            </div>
        </div>
    );
}
