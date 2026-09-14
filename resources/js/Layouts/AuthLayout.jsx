const HERO_IMAGE = "url('/images/auth-abstract-bg.svg')";

export default function AuthLayout({ children }) {
    const brandName = 'YoungFashionShow AI';

    return (
        <main className="login-auth min-h-screen bg-[var(--login-surface)] text-[var(--login-on-surface)] selection:bg-[#c026d3]/20">
            <div className="flex min-h-screen flex-col md:flex-row">
                <section className="split-image-container relative hidden flex-1 flex-col justify-between overflow-hidden p-16 md:flex">
                    <div
                        className="absolute inset-0 z-0 bg-cover bg-center transition-transform duration-[20s] hover:scale-105"
                        style={{ backgroundImage: HERO_IMAGE }}
                        aria-hidden
                    />

                    <div className="relative z-10">
                        <span className="font-display text-2xl font-extrabold tracking-tighter text-[var(--login-pastry-cream)] drop-shadow-sm">
                            {brandName}
                        </span>
                    </div>

                    <div className="relative z-10 max-w-sm">
                        <h1 className="font-display text-5xl font-bold leading-tight text-[var(--login-pastry-cream)] drop-shadow-md">
                            YoungFashionShow
                            <br />
                            AI
                        </h1>
                        <div className="mb-6 mt-4 h-1 w-12 bg-[var(--login-soft-rose)]" />
                        <p className="text-lg leading-relaxed text-[var(--login-pastry-cream)]/90 drop-shadow-sm">
                            Manage the Instagram assistant and upcoming YFS AI services.
                        </p>
                    </div>
                </section>

                <section className="relative flex flex-1 items-center justify-center bg-[var(--login-surface-bright)] px-6 py-12 md:px-16">
                    <div className="w-full max-w-md">{children}</div>
                </section>
            </div>

            <div className="pointer-events-none fixed bottom-0 left-0 hidden h-32 w-32 overflow-hidden opacity-20 md:block">
                <div className="absolute bottom-0 left-0 h-full w-full translate-y-16 -translate-x-16 rotate-45 bg-[var(--login-soft-rose)]" />
            </div>
        </main>
    );
}
