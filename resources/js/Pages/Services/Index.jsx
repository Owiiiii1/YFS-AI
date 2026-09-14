import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_FORM = {
    name: '',
    description: '',
    price: '',
    duration_minutes: '',
    is_active: true,
};

export default function ServicesIndex({ services = [] }) {
    const { locale = 'en' } = usePage().props;
    const [editingService, setEditingService] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const text = {
        en: {
            title: 'Services',
            createTitle: 'Create service',
            listTitle: 'Services list',
            colName: 'Name',
            colPrice: 'Price',
            colDuration: 'Duration',
            colActive: 'Active',
            colActions: 'Actions',
            empty: 'No services yet.',
            editModal: 'Edit service',
            deleteConfirm: 'Delete service?',
            edit: 'Edit',
            delete: 'Delete',
            cancel: 'Cancel',
            save: 'Save',
            update: 'Update',
            close: 'Close',
            active: 'Active',
            yes: 'yes',
            no: 'no',
            fieldName: 'Name',
            fieldPrice: 'Price',
            fieldDuration: 'Duration minutes',
            fieldDescription: 'Description',
        },
        ru: {
            title: 'Услуги',
            createTitle: 'Создать услугу',
            listTitle: 'Список услуг',
            colName: 'Название',
            colPrice: 'Цена',
            colDuration: 'Длительность',
            colActive: 'Активна',
            colActions: 'Действия',
            empty: 'Услуг пока нет.',
            editModal: 'Редактировать услугу',
            deleteConfirm: 'Удалить услугу?',
            edit: 'Изменить',
            delete: 'Удалить',
            cancel: 'Отмена',
            save: 'Сохранить',
            update: 'Обновить',
            close: 'Закрыть',
            active: 'Активна',
            yes: 'да',
            no: 'нет',
            fieldName: 'Название',
            fieldPrice: 'Цена',
            fieldDuration: 'Длительность (мин)',
            fieldDescription: 'Описание',
        },
        uk: {
            title: 'Послуги',
            createTitle: 'Створити послугу',
            listTitle: 'Список послуг',
            colName: 'Назва',
            colPrice: 'Ціна',
            colDuration: 'Тривалість',
            colActive: 'Активна',
            colActions: 'Дії',
            empty: 'Послуг поки що немає.',
            editModal: 'Редагувати послугу',
            deleteConfirm: 'Видалити послугу?',
            edit: 'Редагувати',
            delete: 'Видалити',
            cancel: 'Скасувати',
            save: 'Зберегти',
            update: 'Оновити',
            close: 'Закрити',
            active: 'Активна',
            yes: 'так',
            no: 'ні',
            fieldName: 'Назва',
            fieldPrice: 'Ціна',
            fieldDuration: 'Тривалість (хв)',
            fieldDescription: 'Опис',
        },
    };
    const t = text[locale] ?? text.en;

    const startEdit = (service) => {
        setEditingService(service);
        editForm.setData({
            name: service.name ?? '',
            description: service.description ?? '',
            price: service.price ?? '',
            duration_minutes: service.duration_minutes ?? '',
            is_active: !!service.is_active,
        });
        editForm.clearErrors();
    };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />

            <div className="space-y-6">
                <section className="app-widget p-4">
                    <h2 className="admin-section-title">{t.createTitle}</h2>
                    <form
                        className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('services.store'), {
                                preserveScroll: true,
                                onSuccess: () => createForm.setData({ ...DEFAULT_FORM }),
                            });
                        }}
                    >
                        <Field label={t.fieldName} value={createForm.data.name} onChange={(v) => createForm.setData('name', v)} error={createForm.errors.name} />
                        <Field label={t.fieldPrice} type="number" value={createForm.data.price} onChange={(v) => createForm.setData('price', v)} error={createForm.errors.price} />
                        <Field label={t.fieldDuration} type="number" value={createForm.data.duration_minutes} onChange={(v) => createForm.setData('duration_minutes', v)} error={createForm.errors.duration_minutes} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="service-active"
                                type="checkbox"
                                checked={!!createForm.data.is_active}
                                onChange={(e) => createForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="service-active" className="text-sm text-slate-700">{t.active}</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldDescription}</label>
                            <textarea
                                rows={3}
                                value={createForm.data.description}
                                onChange={(e) => createForm.setData('description', e.target.value)}
                                className="admin-input py-2"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end">
                            <button type="submit" className="admin-btn-primary">{t.save}</button>
                        </div>
                    </form>
                </section>

                <section className="app-widget p-4">
                    <h2 className="admin-section-title">{t.listTitle}</h2>
                    <div className="admin-table-wrap mt-4">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr>
                                    <Th>{t.colName}</Th><Th>{t.colPrice}</Th><Th>{t.colDuration}</Th><Th>{t.colActive}</Th><Th>{t.colActions}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {services.map((service) => (
                                    <tr key={service.id}>
                                        <Td>{service.name}</Td>
                                        <Td>{service.price ?? '—'}</Td>
                                        <Td>{service.duration_minutes ?? '—'}</Td>
                                        <Td>{service.is_active ? t.yes : t.no}</Td>
                                        <Td>
                                            <div className="flex gap-3">
                                                <button type="button" className="admin-link" onClick={() => startEdit(service)}>{t.edit}</button>
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    onClick={() => {
                                                        if (window.confirm(t.deleteConfirm)) {
                                                            router.delete(route('services.destroy', service.id), {
                                                                preserveScroll: true,
                                                            });
                                                        }
                                                    }}
                                                >
                                                    {t.delete}
                                                </button>
                                            </div>
                                        </Td>
                                    </tr>
                                ))}
                                {services.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={5}>{t.empty}</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {editingService && (
                <Modal title={t.editModal} onClose={() => setEditingService(null)} closeLabel={t.close}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('services.update', editingService.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingService(null),
                            });
                        }}
                    >
                        <Field label={t.fieldName} value={editForm.data.name} onChange={(v) => editForm.setData('name', v)} error={editForm.errors.name} />
                        <Field label={t.fieldPrice} type="number" value={editForm.data.price} onChange={(v) => editForm.setData('price', v)} error={editForm.errors.price} />
                        <Field label={t.fieldDuration} type="number" value={editForm.data.duration_minutes} onChange={(v) => editForm.setData('duration_minutes', v)} error={editForm.errors.duration_minutes} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="service-edit-active"
                                type="checkbox"
                                checked={!!editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="service-edit-active" className="text-sm text-slate-700">{t.active}</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldDescription}</label>
                            <textarea
                                rows={3}
                                value={editForm.data.description}
                                onChange={(e) => editForm.setData('description', e.target.value)}
                                className="admin-input py-2"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-2">
                            <button type="button" onClick={() => setEditingService(null)} className="admin-btn-secondary">{t.cancel}</button>
                            <button type="submit" className="admin-btn-primary">{t.update}</button>
                        </div>
                    </form>
                </Modal>
            )}
        </AdminLayout>
    );
}

function Modal({ title, children, onClose, closeLabel = 'Close' }) {
    return (
        <div className="admin-modal-overlay">
            <div className="admin-modal">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="admin-section-title">{title}</h3>
                    <button type="button" className="text-sm text-slate-500" onClick={onClose}>{closeLabel}</button>
                </div>
                {children}
            </div>
        </div>
    );
}

function Field({ label, value, onChange, error, type = 'text' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="admin-input"
            />
            {error && <p className="mt-1 text-sm text-red-600">{error}</p>}
        </div>
    );
}

function Th({ children }) {
    return <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</th>;
}

function Td({ children }) {
    return <td className="px-4 py-3 text-slate-700">{children}</td>;
}
