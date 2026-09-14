import { Link } from '@inertiajs/react';

const links = [
    { href: '/terms-of-service', label: 'Умови використання' },
    { href: '/privacy-policy', label: 'Конфіденційність' },
    { href: '/data-deletion', label: 'Видалення даних' },
];

export default function LegalLayout({ children, title }) {
    return (
        <div className="min-h-screen bg-[#f7f4ff] text-[#1a1230]">
            <header className="border-b border-[#d4c4ea] bg-white/80 backdrop-blur">
                <div className="mx-auto flex max-w-3xl flex-col gap-4 px-6 py-6 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <Link href="/" className="font-display text-xl font-bold tracking-tight text-[#2d1b69]">
                            YoungFashionShow AI
                        </Link>
                        {title ? (
                            <p className="mt-1 text-sm text-[#5b4d73]">{title}</p>
                        ) : null}
                    </div>
                    <nav className="flex flex-wrap gap-3 text-sm">
                        {links.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className="rounded-full border border-[#d4c4ea] px-3 py-1 text-[#2d1b69] transition hover:border-[#f472b6] hover:bg-[#f1ecfb]"
                            >
                                {link.label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-3xl px-6 py-10">{children}</main>

            <footer className="border-t border-[#d4c4ea] bg-white/60">
                <div className="mx-auto max-w-3xl px-6 py-6 text-center text-sm text-[#5b4d73]">
                    © {new Date().getFullYear()} YoungFashionShow AI. All rights reserved.
                </div>
            </footer>
        </div>
    );
}
