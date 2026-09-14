import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const DEFAULT_FORM = {
    name: '',
    email: '',
    phone: '',
    role: '',
    is_active: true,
    notes: '',
};

export default function StaffIndex({ staff = [] }) {
    const { locale = 'en' } = usePage().props;
    const [editingStaff, setEditingStaff] = useState(null);
    const createForm = useForm({ ...DEFAULT_FORM });
    const editForm = useForm({ ...DEFAULT_FORM });

    const text = {
        en: {
            title: 'Staff',
            createTitle: 'Create staff member',
            listTitle: 'Staff list',
            colName: 'Name',
            colEmail: 'Email',
            colPhone: 'Phone',
            colRole: 'Role',
            colActive: 'Active',
            colActions: 'Actions',
            empty: 'No staff yet.',
            editModal: 'Edit staff member',
            deleteConfirm: 'Delete staff member?',
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
            fieldRole: 'Role',
            fieldEmail: 'Email',
            fieldPhone: 'Phone',
            fieldNotes: 'Notes',
        },
        ru: {
            title: 'Сотрудники',
            createTitle: 'Создать сотрудника',
            listTitle: 'Список сотрудников',
            colName: 'Имя',
            colEmail: 'Email',
            colPhone: 'Телефон',
            colRole: 'Роль',
            colActive: 'Активен',
            colActions: 'Действия',
            empty: 'Сотрудников пока нет.',
            editModal: 'Редактировать сотрудника',
            deleteConfirm: 'Удалить сотрудника?',
            edit: 'Изменить',
            delete: 'Удалить',
            cancel: 'Отмена',
            save: 'Сохранить',
            update: 'Обновить',
            close: 'Закрыть',
            active: 'Активен',
            yes: 'да',
            no: 'нет',
            fieldName: 'Имя',
            fieldRole: 'Роль',
            fieldEmail: 'Email',
            fieldPhone: 'Телефон',
            fieldNotes: 'Заметки',
        },
        uk: {
            title: 'Персонал',
            createTitle: 'Створити співробітника',
            listTitle: 'Список персоналу',
            colName: "Ім'я",
            colEmail: 'Email',
            colPhone: 'Телефон',
            colRole: 'Роль',
            colActive: 'Активний',
            colActions: 'Дії',
            empty: 'Персоналу поки що немає.',
            editModal: 'Редагувати співробітника',
            deleteConfirm: 'Видалити співробітника?',
            edit: 'Редагувати',
            delete: 'Видалити',
            cancel: 'Скасувати',
            save: 'Зберегти',
            update: 'Оновити',
            close: 'Закрити',
            active: 'Активний',
            yes: 'так',
            no: 'ні',
            fieldName: "Ім'я",
            fieldRole: 'Роль',
            fieldEmail: 'Email',
            fieldPhone: 'Телефон',
            fieldNotes: 'Нотатки',
        },
    };
    const t = text[locale] ?? text.en;

    const startEdit = (member) => {
        setEditingStaff(member);
        editForm.setData({
            name: member.name ?? '',
            email: member.email ?? '',
            phone: member.phone ?? '',
            role: member.role ?? '',
            is_active: !!member.is_active,
            notes: member.notes ?? '',
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
                            createForm.post(route('staff.store'), {
                                preserveScroll: true,
                                onSuccess: () => createForm.setData({ ...DEFAULT_FORM }),
                            });
                        }}
                    >
                        <Field label={t.fieldName} value={createForm.data.name} onChange={(v) => createForm.setData('name', v)} error={createForm.errors.name} />
                        <Field label={t.fieldRole} value={createForm.data.role} onChange={(v) => createForm.setData('role', v)} error={createForm.errors.role} />
                        <Field label={t.fieldEmail} type="email" value={createForm.data.email} onChange={(v) => createForm.setData('email', v)} error={createForm.errors.email} />
                        <Field label={t.fieldPhone} value={createForm.data.phone} onChange={(v) => createForm.setData('phone', v)} error={createForm.errors.phone} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="staff-active"
                                type="checkbox"
                                checked={!!createForm.data.is_active}
                                onChange={(e) => createForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="staff-active" className="text-sm text-slate-700">{t.active}</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldNotes}</label>
                            <textarea
                                rows={3}
                                value={createForm.data.notes}
                                onChange={(e) => createForm.setData('notes', e.target.value)}
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
                                    <Th>{t.colName}</Th><Th>{t.colEmail}</Th><Th>{t.colPhone}</Th><Th>{t.colRole}</Th><Th>{t.colActive}</Th><Th>{t.colActions}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {staff.map((member) => (
                                    <tr key={member.id}>
                                        <Td>{member.name}</Td>
                                        <Td>{member.email || '—'}</Td>
                                        <Td>{member.phone || '—'}</Td>
                                        <Td>{member.role || '—'}</Td>
                                        <Td>{member.is_active ? t.yes : t.no}</Td>
                                        <Td>
                                            <div className="flex gap-3">
                                                <button type="button" className="admin-link" onClick={() => startEdit(member)}>{t.edit}</button>
                                                <button
                                                    type="button"
                                                    className="text-red-700"
                                                    onClick={() => {
                                                        if (window.confirm(t.deleteConfirm)) {
                                                            router.delete(route('staff.destroy', member.id), {
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
                                {staff.length === 0 && (
                                    <tr>
                                        <td className="px-4 py-5 text-slate-500" colSpan={6}>{t.empty}</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {editingStaff && (
                <Modal title={t.editModal} onClose={() => setEditingStaff(null)} closeLabel={t.close}>
                    <form
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('staff.update', editingStaff.id), {
                                preserveScroll: true,
                                onSuccess: () => setEditingStaff(null),
                            });
                        }}
                    >
                        <Field label={t.fieldName} value={editForm.data.name} onChange={(v) => editForm.setData('name', v)} error={editForm.errors.name} />
                        <Field label={t.fieldRole} value={editForm.data.role} onChange={(v) => editForm.setData('role', v)} error={editForm.errors.role} />
                        <Field label={t.fieldEmail} type="email" value={editForm.data.email} onChange={(v) => editForm.setData('email', v)} error={editForm.errors.email} />
                        <Field label={t.fieldPhone} value={editForm.data.phone} onChange={(v) => editForm.setData('phone', v)} error={editForm.errors.phone} />
                        <div className="flex items-center gap-2 pt-7">
                            <input
                                id="staff-edit-active"
                                type="checkbox"
                                checked={!!editForm.data.is_active}
                                onChange={(e) => editForm.setData('is_active', e.target.checked)}
                            />
                            <label htmlFor="staff-edit-active" className="text-sm text-slate-700">{t.active}</label>
                        </div>
                        <div className="md:col-span-2">
                            <label className="mb-1 block text-sm font-medium text-slate-600">{t.fieldNotes}</label>
                            <textarea
                                rows={3}
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                                className="admin-input py-2"
                            />
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-2">
                            <button type="button" onClick={() => setEditingStaff(null)} className="admin-btn-secondary">{t.cancel}</button>
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
