import LegalDocument, { LegalSection } from '@/Components/LegalDocument';
import LegalLayout from '@/Layouts/LegalLayout';
import { Head } from '@inertiajs/react';

export default function PrivacyPolicy({ appName, appUrl, contactEmail, lastUpdated }) {
    return (
        <LegalLayout title="Політика конфіденційності">
            <Head title="Політика конфіденційності" />

            <LegalDocument lastUpdated={lastUpdated}>
                <LegalSection title="1. Загальні положення">
                    <p>
                        Ця Політика конфіденційності описує, як {appName} («ми», «нас», «наш») збирає,
                        використовує та захищає персональні дані користувачів сервісу {appUrl} та пов’язаних
                        каналів комунікації, зокрема Instagram Direct.
                    </p>
                    <p>
                        Користуючись нашим сервісом або надсилаючи повідомлення в Instagram, ви погоджуєтесь з
                        умовами цієї Політики.
                    </p>
                </LegalSection>

                <LegalSection title="2. Які дані ми збираємо">
                    <p>Ми можемо обробляти такі категорії даних:</p>
                    <ul className="list-disc space-y-2 pl-6">
                        <li>ідентифікатор Instagram та ім’я користувача (username);</li>
                        <li>текст повідомлень у діалозі Instagram Direct;</li>
                        <li>метадані повідомлень (час надсилання, ідентифікатор повідомлення);</li>
                        <li>контактні дані, які ви добровільно надаєте (ім’я, телефон, email, адреса доставки);</li>
                        <li>інформація про замовлення, статуси та історію взаємодії з кондитерською.</li>
                    </ul>
                </LegalSection>

                <LegalSection title="3. Навіщо ми використовуємо дані">
                    <ul className="list-disc space-y-2 pl-6">
                        <li>обробка запитів і замовлень;</li>
                        <li>відповіді через Instagram Direct, у тому числі за допомогою автоматизованого помічника;</li>
                        <li>покращення якості обслуговування та внутрішнього CRM;</li>
                        <li>дотримання вимог законодавства та захист наших законних інтересів.</li>
                    </ul>
                </LegalSection>

                <LegalSection title="4. Правова підстава обробки">
                    <p>
                        Ми обробляємо дані на підставі вашої згоди (надсилання повідомлення в Instagram), виконання
                        договору (оформлення замовлення) та законного інтересу (ведення обліку звернень і замовлень).
                    </p>
                </LegalSection>

                <LegalSection title="5. Передача даних третім сторонам">
                    <p>Дані можуть передаватися лише для надання сервісу:</p>
                    <ul className="list-disc space-y-2 pl-6">
                        <li>Meta / Instagram — для отримання та надсилання повідомлень;</li>
                        <li>постачальники хмарної інфраструктури та AI-сервісів — для технічної обробки запитів;</li>
                        <li>державні органи — у випадках, передбачених законом.</li>
                    </ul>
                    <p>Ми не продаємо персональні дані третім сторонам.</p>
                </LegalSection>

                <LegalSection title="6. Зберігання та безпека">
                    <p>
                        Дані зберігаються протягом часу, необхідного для обробки замовлень, ведення діалогів та
                        виконання юридичних зобов’язань. Ми застосовуємо організаційні та технічні заходи для захисту
                        даних від несанкціонованого доступу.
                    </p>
                </LegalSection>

                <LegalSection title="7. Ваші права">
                    <p>Ви маєте право:</p>
                    <ul className="list-disc space-y-2 pl-6">
                        <li>отримати інформацію про обробку ваших даних;</li>
                        <li>вимагати виправлення або видалення даних;</li>
                        <li>обмежити або заперечити проти обробки у випадках, передбачених законом;</li>
                        <li>подати скаргу до уповноваженого органу з захисту персональних даних.</li>
                    </ul>
                    <p>
                        Щоб скористатися правами, напишіть на{' '}
                        <a href={`mailto:${contactEmail}`} className="text-[#7c3aed] underline">
                            {contactEmail}
                        </a>{' '}
                        або скористайтеся сторінкою{' '}
                        <a href="/data-deletion" className="text-[#7c3aed] underline">
                            запиту на видалення даних
                        </a>
                        .
                    </p>
                </LegalSection>

                <LegalSection title="8. Контакти">
                    <p>
                        З питань конфіденційності звертайтесь:{' '}
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
