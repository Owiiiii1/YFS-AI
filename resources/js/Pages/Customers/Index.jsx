import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

const DEFAULT_FORM = {
    name: '',
    email: '',
    phone: '',
    address: '',
    status: 'active',
    notes: '',
};

export default function CustomersIndex({ customers = [] }) {
    const { locale = 'en', statusLabels = {} } = usePage().props;
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [editingCustomer, setEditingCustomer] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const text = {
        en: {
            title: 'Customers',
            listTitle: 'Customers list',
            create: 'Create',
            colName: 'Name',
            colEmail: 'Email',
            colPhone: 'Phone',
            colStatus: 'Status',
            empty: 'No customers yet.',
            createModal: 'Create customer',
            viewModal: 'Customer',
            deleteConfirm: 'Permanently delete this customer and everything linked to them: applications, orders, calendar reservations, dialogs, messages, payment receipts, and uploaded files? This cannot be undone.',
            delete: 'Delete',
            cancel: 'Cancel',
            save: 'Save',
            update: 'Update',
            close: 'Close',
            fieldName: 'Name',
            fieldEmail: 'Email',
            fieldPhone: 'Phone',
            fieldAddress: 'Address',
            fieldStatus: 'Status',
            fieldNotes: 'Notes',
        },
        ru: {
            title: 'Клиенты',
            listTitle: 'Список клиентов',
            create: 'Создать',
            colName: 'Имя',
            colEmail: 'Email',
            colPhone: 'Телефон',
            colStatus: 'Статус',
            empty: 'Клиентов пока нет.',
            createModal: 'Создать клиента',
            viewModal: 'Клиент',
            deleteConfirm: 'Навсегда удалить клиента и всё, что с ним связано: заявки, заказы, бронирования в календаре, диалоги, сообщения, квитанции и загруженные файлы? Это действие нельзя отменить.',
            delete: 'Удалить',
            cancel: 'Отмена',
            save: 'Сохранить',
            update: 'Обновить',
            close: 'Закрыть',
            fieldName: 'Имя',
            fieldEmail: 'Email',
            fieldPhone: 'Телефон',
            fieldAddress: 'Адрес',
            fieldStatus: 'Статус',
            fieldNotes: 'Заметки',
        },
        uk: {
            title: 'Клієнти',
            listTitle: 'Список клієнтів',
            create: 'Створити',
            colName: "Ім'я",
            colEmail: 'Email',
            colPhone: 'Телефон',
            colStatus: 'Статус',
            empty: 'Клієнтів поки що немає.',
            createModal: 'Створити клієнта',
            viewModal: 'Клієнт',
            deleteConfirm: 'Назавжди видалити клієнта й усе, що з ним пов’язано: заявки, замовлення, бронювання в календарі, діалоги, повідомлення, квитанції та завантажені файли? Цю дію неможливо скасувати.',
            delete: 'Видалити',
            cancel: 'Скасувати',
            save: 'Зберегти',
            update: 'Оновити',
            close: 'Закрити',
            fieldName: "Ім'я",
            fieldEmail: 'Email',
            fieldPhone: 'Телефон',
            fieldAddress: 'Адреса',
            fieldStatus: 'Статус',
            fieldNotes: 'Нотатки',
        },
    };
    const t = text[locale] ?? text.en;
    const statusLabel = (key) => statusLabels[key] ?? key;

    const startEdit = (customer) => {
        setEditingCustomer(customer);
        editForm.setData({
            name: customer.name ?? '',
            email: customer.email ?? '',
            phone: customer.phone ?? '',
            address: customer.address ?? '',
            status: customer.status ?? 'active',
            notes: customer.notes ?? '',
        });
        editForm.clearErrors();
    };

    const closeCreateModal = () => {
        setShowCreateModal(false);
        createForm.setData({ ...DEFAULT_FORM });
        createForm.clearErrors();
    };

    const closeEditModal = () => {
        setEditingCustomer(null);
        editForm.clearErrors();
    };

    const deleteCustomer = () => {
        if (!editingCustomer || !window.confirm(t.deleteConfirm)) {
            return;
        }

        router.delete(route('customers.destroy', editingCustomer.id), {
            preserveScroll: true,
            onSuccess: closeEditModal,
        });
    };

    return (
        <AdminLayout title={t.title}>
            <Head title={t.title} />

            <div className="space-y-6">
                <section className="app-widget p-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="admin-section-title">{t.listTitle}</h2>
                        <button
                            type="button"
                            onClick={() => setShowCreateModal(true)}
                            className="admin-btn-primary"
                        >
                            <Plus className="h-4 w-4" />
                            {t.create}
                        </button>
                    </div>
                    <div className="admin-table-wrap mt-4">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr>
                                    <Th>{t.colName}</Th><Th>{t.colEmail}</Th><Th>{t.colPhone}</Th><Th>{t.colStatus}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.map((customer) => (
                                    <tr
                                        key={customer.id}
                                        onClick={() => startEdit(customer)}
                                        className={`cursor-pointer transition hover:bg-[var(--admin-surface-container-low)] ${
                                            editingCustomer?.id === customer.id ? 'admin-table-row-active' : ''
                                        }`}
                                    >
                                        <Td>{customer.name}</Td>
                                        <Td>{customer.email || '—'}</Td>
                                        <Td>{customer.phone || '—'}</Td>
                                        <Td>{statusLabel(customer.status)}</Td>
                                    </tr>
                                ))}
                                {customers.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={4}>{t.empty}</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {showCreateModal && (
                <Modal title={t.createModal} onClose={closeCreateModal} closeLabel={t.close}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('customers.store'), {
                                preserveScroll: true,
                                onSuccess: closeCreateModal,
                            });
                        }}
                    >
                        <Field label={t.fieldName} value={createForm.data.name} onChange={(v) => createForm.setData('name', v)} error={createForm.errors.name} />
                        <Field label={t.fieldEmail} value={createForm.data.email} onChange={(v) => createForm.setData('email', v)} error={createForm.errors.email} type="email" />
                        <Field label={t.fieldPhone} value={createForm.data.phone} onChange={(v) => createForm.setData('phone', v)} error={createForm.errors.phone} />
                        <Field label={t.fieldAddress} value={createForm.data.address} onChange={(v) => createForm.setData('address', v)} error={createForm.errors.address} />
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldStatus}</label>
                            <select
                                value={createForm.data.status}
                                onChange={(e) => createForm.setData('status', e.target.value)}
                                className="admin-input"
                            >
                                <option value="active">{statusLabel('active')}</option>
                                <option value="inactive">{statusLabel('inactive')}</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldNotes}</label>
                            <textarea
                                value={createForm.data.notes}
                                onChange={(e) => createForm.setData('notes', e.target.value)}
                                rows={3}
                                className="admin-input py-2"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-2">
                            <button type="button" onClick={closeCreateModal} className="admin-btn-secondary">{t.cancel}</button>
                            <button type="submit" className="admin-btn-primary">{t.save}</button>
                        </div>
                    </form>
                </Modal>
            )}

            {editingCustomer && (
                <Modal title={t.viewModal} onClose={closeEditModal} closeLabel={t.close}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('customers.update', editingCustomer.id), {
                                preserveScroll: true,
                                onSuccess: closeEditModal,
                            });
                        }}
                    >
                        <Field label={t.fieldName} value={editForm.data.name} onChange={(v) => editForm.setData('name', v)} error={editForm.errors.name} />
                        <Field label={t.fieldEmail} value={editForm.data.email} onChange={(v) => editForm.setData('email', v)} error={editForm.errors.email} type="email" />
                        <Field label={t.fieldPhone} value={editForm.data.phone} onChange={(v) => editForm.setData('phone', v)} error={editForm.errors.phone} />
                        <Field label={t.fieldAddress} value={editForm.data.address} onChange={(v) => editForm.setData('address', v)} error={editForm.errors.address} />
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldStatus}</label>
                            <select
                                value={editForm.data.status}
                                onChange={(e) => editForm.setData('status', e.target.value)}
                                className="admin-input"
                            >
                                <option value="active">{statusLabel('active')}</option>
                                <option value="inactive">{statusLabel('inactive')}</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldNotes}</label>
                            <textarea
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                                rows={3}
                                className="admin-input py-2"
                            />
                        </div>
                        <div className="md:col-span-2 flex items-center justify-between gap-2">
                            <button
                                type="button"
                                onClick={deleteCustomer}
                                className="text-sm font-medium text-red-700 transition hover:text-red-800"
                            >
                                {t.delete}
                            </button>
                            <div className="flex gap-2">
                                <button type="button" onClick={closeEditModal} className="admin-btn-secondary">{t.cancel}</button>
                                <button type="submit" className="admin-btn-primary">{t.update}</button>
                            </div>
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

function Th({ children }) {
    return <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</th>;
}

function Td({ children }) {
    return <td className="px-4 py-3 text-slate-700">{children}</td>;
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
