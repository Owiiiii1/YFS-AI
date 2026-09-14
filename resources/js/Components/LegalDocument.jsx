export default function LegalDocument({ lastUpdated, children }) {
    return (
        <article className="legal-document space-y-8 text-[15px] leading-7 text-[#3d2a5c]">
            {lastUpdated ? (
                <p className="text-sm text-[#5b4d73]">Останнє оновлення: {lastUpdated}</p>
            ) : null}
            {children}
        </article>
    );
}

export function LegalSection({ title, children }) {
    return (
        <section className="space-y-3">
            <h2 className="font-display text-xl font-semibold text-[#2d1b69]">{title}</h2>
            <div className="space-y-3">{children}</div>
        </section>
    );
}
