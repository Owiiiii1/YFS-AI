import LegalDocument, { LegalSection } from '@/Components/LegalDocument';
import LegalLayout from '@/Layouts/LegalLayout';
import { Head } from '@inertiajs/react';

export default function DataDeletion({ appName, appUrl, contactEmail, lastUpdated }) {
    return (
        <LegalLayout title="Запит на видалення даних">
            <Head title="Запит на видалення даних" />

            <LegalDocument lastUpdated={lastUpdated}>
                <LegalSection title="1. Про цю сторінку">
                    <p>
                        Ця сторінка пояснює, як користувачі Instagram та клієнти {appName} можуть подати запит на
                        видалення персональних даних, які ми обробляємо через сервіс {appUrl} та інтеграцію з Instagram
                        Direct.
                    </p>
                </LegalSection>

                <LegalSection title="2. Які дані можна видалити">
                    <p>За вашим запитом ми можемо видалити або анонімізувати:</p>
                    <ul className="list-disc space-y-2 pl-6">
                        <li>історію діалогу Instagram Direct, збережену в нашій CRM;</li>
                        <li>контактні дані (ім’я, телефон, email, адресу), надані в переписці;</li>
                        <li>пов’язані записи про замовлення, якщо їх видалення не суперечить обов’язкам бухгалтерського обліку.</li>
                    </ul>
                </LegalSection>

                <LegalSection title="3. Як подати запит">
                    <p>Надішліть лист на email:</p>
                    <p>
                        <a href={`mailto:${contactEmail}?subject=${encodeURIComponent('Запит на видалення даних')}`} className="text-[#7c3aed] underline">
                            {contactEmail}
                        </a>
                    </p>
                    <p>У листі вкажіть:</p>
                    <ul className="list-disc space-y-2 pl-6">
                        <li>ваш Instagram username (обов’язково);</li>
                        <li>контактний email або телефон для підтвердження особи;</li>
                        <li>які саме дані потрібно видалити;</li>
                        <li>додатковий коментар, якщо потрібно.</li>
                    </ul>
                    <p>
                        Альтернативно ви можете написати нам у Instagram Direct з того ж акаунта, з якого надсилали
                        повідомлення, із текстом: «Запит на видалення моїх даних».
                    </p>
                </LegalSection>

                <LegalSection title="4. Строки обробки">
                    <p>
                        Ми розглядаємо запити протягом 30 календарних днів. У складних випадках строк може бути
                        продовжено відповідно до законодавства, про що ми повідомимо вас окремо.
                    </p>
                </LegalSection>

                <LegalSection title="5. Що залишиться після видалення">
                    <p>
                        Після видалення ваші повідомлення та профіль клієнта будуть прибрані з нашої CRM. Окремі
                        технічні журнали можуть зберігатися обмежений час для безпеки та усунення збоїв, без
                        використання для маркетингу.
                    </p>
                    <p>
                        Дані, які ми зобов’язані зберігати за законом (наприклад, фінансові документи), будуть
                        збережені лише в межах, передбачених законодавством.
                    </p>
                </LegalSection>

                <LegalSection title="6. Meta / Instagram">
                    <p>
                        Видалення даних у нашій системі не видаляє автоматично історію чату в застосунку Instagram.
                        Для видалення повідомлень у самому Instagram скористайтеся інструментами Meta.
                    </p>
                    <p>
                        You can also request deletion by disconnecting this app in Meta / Instagram
                        (Settings → Apps and websites, or Instagram Business Login settings). Meta then sends us a
                        deletion request automatically.
                    </p>
                </LegalSection>

                <LegalSection title="7. Контакти">
                    <p>
                        З питань видалення даних:{' '}
                        <a href={`mailto:${contactEmail}`} className="text-[#7c3aed] underline">
                            {contactEmail}
                        </a>
                        .
                    </p>
                </LegalSection>
            </LegalDocument>
        </LegalLayout>
    );
}
