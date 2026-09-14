export function escapeHtml(value = '') {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

export function linkifyText(value = '') {
    const escaped = escapeHtml(value);
    const withBreaks = escaped.replace(/\n/g, '<br />');

    return withBreaks.replace(
        /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g,
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="underline break-all hover:opacity-80">$1</a>',
    );
}

export function formatMessageTime(iso) {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString(undefined, {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return iso;
    }
}
