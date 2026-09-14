import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { Button } from '@/Components/ui/button';
import { Link, usePage, usePoll } from '@inertiajs/react';
import {
    Bot,
    ChartColumn,
    ChevronDown,
    FileText,
    Home,
    LogOut,
    Menu,
    MessageCircle,
    Phone,
    Settings,
    UserCircle2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/Components/ui/sheet';

const instagramNavItems = [
    { route: 'dialogs.instagram', icon: MessageCircle, key: 'dialogsInstagram' },
    { route: 'questionnaires.index', icon: FileText, key: 'questionnaires' },
    { route: 'bot-management.index', icon: Bot, key: 'botManagement' },
];

const callCenterNavItems = [
    { route: 'call-center.index', icon: Phone, key: 'callCenterHome' },
];

const bottomNavItems = [
    { route: 'settings.index', icon: Settings, key: 'settings' },
];

export default function AdminLayout({ title, children }) {
    const {
        auth,
        locale = 'en',
        owlAdmin = {},
        dialogsUnreadCount = 0,
        instagramDialogsUnreadCount = 0,
        applicationsCount = 0,
        instagramConnection = null,
        telegramConnection = null,
    } = usePage().props;
    const user = auth?.user;
    const companyName = owlAdmin?.brand_name || 'YoungFashionShow AI';
    const ai = owlAdmin?.ai ?? {};
    const aiConnected = !!ai.connected;
    const aiBadgeText = ai?.status_label
        ?? (aiConnected
            ? `AI: connected — ${ai.provider_label ?? ai.provider ?? 'Unknown'} / ${ai.model ?? 'unknown'}`
            : 'AI: not connected');
    const instagram = instagramConnection ?? owlAdmin?.instagram ?? {};
    const instagramConnected = !!instagram.connected;
    const instagramBadgeText = instagram.status_label
        ?? (instagramConnected ? 'Instagram: connected' : 'Instagram: not connected');
    const telegram = telegramConnection ?? owlAdmin?.telegram ?? {};
    const telegramConnected = !!telegram.connected;
    const telegramBadgeText = telegram.status_label
        ?? (telegramConnected ? 'Telegram: connected' : 'Telegram: not connected');
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [statisticsOpen, setStatisticsOpen] = useState(route().current('statistics.*'));
    const [profileOpen, setProfileOpen] = useState(false);
    const profileMenuRef = useRef(null);

    usePoll(
        3000,
        {
            only: ['dialogsUnreadCount', 'instagramDialogsUnreadCount', 'applicationsCount', 'instagramConnection', 'telegramConnection'],
            preserveScroll: true,
            preserveState: true,
            showProgress: false,
        },
        { autoStart: !!user, keepAlive: true },
    );

    useEffect(() => {
        const handleOutsideClick = (event) => {
            if (profileMenuRef.current && !profileMenuRef.current.contains(event.target)) {
                setProfileOpen(false);
            }
        };

        document.addEventListener('mousedown', handleOutsideClick);

        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, []);

    const uiText = {
        en: {
            home: 'Home',
            instagram: 'Instagram',
            questionnaires: 'Bot replies',
            dialogsInstagram: 'Dialogs',
            botManagement: 'Bot management',
            callCenter: 'Call center',
            callCenterHome: 'Voice assistant',
            settings: 'Settings',
            statistics: 'Reports',
            logs: 'Logs',
            profile: 'Profile',
            logout: 'Logout',
            adminPanel: 'Admin Panel',
            poweredBy: 'Powered by',
        },
        ru: {
            home: 'Главная',
            instagram: 'Instagram',
            questionnaires: 'Ответы бота',
            dialogsInstagram: 'Диалоги',
            botManagement: 'Управление ботом',
            callCenter: 'Колл-центр',
            callCenterHome: 'Голосовой ассистент',
            settings: 'Настройки',
            statistics: 'Отчёты',
            logs: 'Логи',
            profile: 'Профиль',
            logout: 'Выход',
            adminPanel: 'Панель администратора',
            poweredBy: 'Powered by',
        },
        uk: {
            home: 'Головна',
            instagram: 'Instagram',
            questionnaires: 'Відповіді бота',
            dialogsInstagram: 'Діалоги',
            botManagement: 'Керування ботом',
            callCenter: 'Кол-центр',
            callCenterHome: 'Голосовий асистент',
            settings: 'Налаштування',
            statistics: 'Звіти',
            logs: 'Логи',
            profile: 'Профіль',
            logout: 'Вихід',
            adminPanel: 'Панель адміністратора',
            poweredBy: 'Powered by',
        },
    };
    const t = uiText[locale] ?? uiText.en;

    const navLinkClass = (active) =>
        `flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${
            active
                ? 'border-l-2 border-[var(--admin-soft-rose)] bg-white/10 text-[var(--admin-pastry-cream)]'
                : 'text-[var(--admin-pastry-cream)]/70 hover:bg-white/5 hover:text-[var(--admin-pastry-cream)]'
        }`;

    const isNavActive = (routeName) => {
        if (routeName === 'dialogs.instagram') {
            return route().current(routeName);
        }

        return route().current(`${routeName.split('.')[0]}*`) || route().current(routeName);
    };

    const renderNavLinks = (items, mobile = false) =>
        items.map(({ route: routeName, icon: Icon, key }) => (
            <Link
                key={`${mobile ? 'mobile-' : ''}${routeName}`}
                href={route(routeName)}
                className={navLinkClass(isNavActive(routeName))}
                onClick={() => mobile && setMobileMenuOpen(false)}
            >
                <Icon className="h-4 w-4 shrink-0" />
                <span className="flex-1">{t[key]}</span>
                {routeName === 'dialogs.instagram' && instagramDialogsUnreadCount > 0 && (
                    <span className="admin-nav-unread-badge">
                        {instagramDialogsUnreadCount > 99 ? '99+' : instagramDialogsUnreadCount}
                    </span>
                )}
                {routeName === 'questionnaires.index' && applicationsCount > 0 && (
                    <span className="admin-nav-unread-badge">
                        {applicationsCount > 99 ? '99+' : applicationsCount}
                    </span>
                )}
            </Link>
        ));

    const renderStatistics = (mobile = false) => (
        <div>
            <button
                type="button"
                onClick={() => setStatisticsOpen((open) => !open)}
                className={`${navLinkClass(route().current('statistics.*'))} w-full`}
            >
                <ChartColumn className="h-4 w-4" />
                {t.statistics}
                <ChevronDown className={`ml-auto h-4 w-4 transition ${statisticsOpen ? 'rotate-180' : ''}`} />
            </button>

            {statisticsOpen && (
                <Link
                    href={route('statistics.logs')}
                    className={`${navLinkClass(route().current('statistics.logs'))} ml-5 mt-1`}
                    onClick={() => mobile && setMobileMenuOpen(false)}
                >
                    <FileText className="h-4 w-4" />
                    {t.logs}
                </Link>
            )}
        </div>
    );

    const renderNavSection = (title, items, mobile = false) => (
        <div className="space-y-1">
            <p className="px-3 pb-1 pt-2 font-label text-[11px] font-semibold uppercase tracking-wider text-[var(--admin-pastry-cream)]/45">
                {title}
            </p>
            <div className="space-y-1 pl-1">
                {renderNavLinks(items, mobile)}
            </div>
        </div>
    );

    const renderSidebarNav = (mobile = false) => (
        <>
            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto">
                {renderNavLinks([{ route: 'dashboard', icon: Home, key: 'home' }], mobile)}
                {renderNavSection(t.instagram, instagramNavItems, mobile)}
                {renderNavSection(t.callCenter, callCenterNavItems, mobile)}
            </div>

            <div className="mt-auto shrink-0 space-y-1.5 border-t border-white/10 pt-4">
                {renderNavLinks(bottomNavItems, mobile)}
                {renderStatistics(mobile)}
            </div>
        </>
    );

    const sidebarFooter = (
        <div className="mt-auto border-t border-white/10 px-4 pt-4 pb-6">
            <p className="text-xs text-[var(--admin-pastry-cream)]/50">
                {t.poweredBy}{' '}
                <a
                    href="https://owlsolutions.net"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-[var(--admin-pastry-cream)]/70 transition hover:text-[var(--admin-pastry-cream)]"
                >
                    OwlSolutions
                </a>
            </p>
        </div>
    );

    return (
        <div className="admin-shell h-screen overflow-hidden bg-[var(--admin-surface)]">
            <div className="flex h-screen">
                <aside className="hidden w-72 flex-col bg-[var(--admin-sidebar)] text-[var(--admin-pastry-cream)] shadow-2xl lg:flex">
                    <div className="border-b border-white/10 px-6 py-5">
                        <div>
                            <p className="font-display text-2xl font-extrabold tracking-tighter text-[var(--admin-pastry-cream)]">
                                {companyName}
                            </p>
                            <p className="font-label mt-1 text-xs uppercase tracking-wide text-[var(--admin-pastry-cream)]/50">{t.adminPanel}</p>
                        </div>
                    </div>

                    <nav className="flex min-h-0 flex-1 flex-col px-4 pt-4 pb-2">
                        {renderSidebarNav()}
                        {sidebarFooter}
                    </nav>
                </aside>

                <Sheet open={mobileMenuOpen} onOpenChange={setMobileMenuOpen}>
                    <SheetContent side="left" className="w-80 border-r-0 bg-[var(--admin-sidebar)] p-0 text-[var(--admin-pastry-cream)]">
                        <SheetHeader className="border-b border-white/10 px-6 py-5 text-left">
                            <SheetTitle className="font-display text-2xl font-extrabold tracking-tighter text-[var(--admin-pastry-cream)]">
                                {companyName}
                            </SheetTitle>
                            <p className="font-label mt-1 text-xs uppercase tracking-wide text-[var(--admin-pastry-cream)]/50">{t.adminPanel}</p>
                        </SheetHeader>
                        <nav className="flex min-h-0 flex-1 flex-col px-4 pt-4 pb-2">
                            {renderSidebarNav(true)}
                            {sidebarFooter}
                        </nav>
                    </SheetContent>
                </Sheet>

                <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                    <header className="sticky top-0 z-20 border-b border-[var(--admin-outline-variant)] bg-[var(--admin-surface-bright)]/90 backdrop-blur-xl">
                        <div className="flex h-16 items-center justify-between px-4 sm:px-8">
                            <div className="flex items-center gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    className="h-9 w-9 shrink-0 rounded-full border-[var(--admin-outline-variant)] lg:hidden"
                                    onClick={() => setMobileMenuOpen(true)}
                                >
                                    <Menu className="h-4 w-4" />
                                </Button>
                                <h1 className="admin-page-title">{title}</h1>
                            </div>
                            <div className="flex items-center gap-3">
                                <Link
                                    href={route('settings.index', { tab: 'instagram' })}
                                    className={`inline-flex max-w-[180px] truncate rounded-full px-3 py-1 text-xs font-semibold sm:max-w-[280px] ${
                                        instagramConnected
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-red-100 text-red-700'
                                    }`}
                                    title={instagramBadgeText}
                                >
                                    {instagramBadgeText}
                                </Link>
                                <Link
                                    href={route('settings.index', { tab: 'telegram' })}
                                    className={`inline-flex max-w-[180px] truncate rounded-full px-3 py-1 text-xs font-semibold sm:max-w-[280px] ${
                                        telegramConnected
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-red-100 text-red-700'
                                    }`}
                                    title={telegramBadgeText}
                                >
                                    {telegramBadgeText}
                                </Link>
                                <span
                                    className={`hidden max-w-[320px] truncate rounded-full px-3 py-1 text-xs font-semibold lg:inline-flex ${
                                        aiConnected
                                            ? 'bg-emerald-100 text-emerald-800'
                                            : 'bg-red-100 text-red-700'
                                    }`}
                                    title={aiBadgeText}
                                >
                                    {aiBadgeText}
                                </span>
                                <LanguageSwitcher locale={locale} className="admin-lang-switcher" />
                                {user && (
                                    <div className="relative z-30 shrink-0" ref={profileMenuRef}>
                                        <button
                                            type="button"
                                            onClick={() => setProfileOpen((open) => !open)}
                                            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-[var(--admin-outline-variant)] bg-[var(--admin-surface-bright)] text-[var(--admin-on-surface-variant)] shadow-sm transition hover:bg-[var(--admin-surface-container-low)]"
                                            aria-label={t.profile}
                                            aria-expanded={profileOpen}
                                        >
                                            <UserCircle2 className="h-5 w-5" />
                                        </button>

                                        {profileOpen && (
                                            <div className="absolute right-0 top-full z-50 mt-2 w-44 overflow-hidden rounded-xl border border-[var(--admin-outline-variant)] bg-[var(--admin-surface-bright)] py-1 shadow-lg">
                                                <Link
                                                    href={route('profile.edit')}
                                                    className="flex items-center gap-2 px-3 py-2 text-sm text-[var(--admin-on-surface)] transition hover:bg-[var(--admin-surface-container-low)]"
                                                    onClick={() => setProfileOpen(false)}
                                                >
                                                    <UserCircle2 className="h-4 w-4" />
                                                    {t.profile}
                                                </Link>
                                                <div className="my-1 h-px bg-[var(--admin-outline-variant)]" />
                                                <Link
                                                    href={route('logout')}
                                                    method="post"
                                                    as="button"
                                                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-[var(--admin-on-surface)] transition hover:bg-[var(--admin-surface-container-low)]"
                                                    onClick={() => setProfileOpen(false)}
                                                >
                                                    <LogOut className="h-4 w-4" />
                                                    {t.logout}
                                                </Link>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </header>

                    <main className="flex-1 overflow-y-auto bg-[var(--admin-surface)] p-4 sm:p-8">
                        <div className="admin-content-card">
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </div>
    );
}
