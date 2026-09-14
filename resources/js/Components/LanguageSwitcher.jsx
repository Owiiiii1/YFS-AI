import { router } from '@inertiajs/react';
import { ChevronDown, Globe } from 'lucide-react';

const languageLabels = { ru: 'Русский', en: 'English', uk: 'Українська' };

export default function LanguageSwitcher({ locale = 'en', className = '' }) {
    const changeLocale = (nextLocale) => {
        if (nextLocale === locale) {
            return;
        }

        router.post(
            route('settings.language.update'),
            { locale: nextLocale },
            { preserveScroll: true },
        );
    };

    return (
        <div className={`relative w-fit shrink-0 ${className}`}>
            <label htmlFor="admin-header-language" className="sr-only">Language</label>
            <div className="inline-flex h-9 items-center gap-2 rounded-full border border-slate-300 bg-white px-3 pr-9 text-sm font-medium text-slate-700 shadow-sm admin-lang-trigger">
                <Globe className="h-4 w-4 text-slate-500" />
                <span>{languageLabels[locale] ?? languageLabels.en}</span>
                <ChevronDown className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" />
            </div>
            <select
                id="admin-header-language"
                value={locale}
                onChange={(e) => changeLocale(e.target.value)}
                className="absolute inset-0 h-9 w-full cursor-pointer opacity-0"
            >
                <option value="uk">Українська</option>
                <option value="en">English</option>
                <option value="ru">Русский</option>
            </select>
        </div>
    );
}
