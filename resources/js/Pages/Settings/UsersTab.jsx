import { useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

export default function UsersTab({ users = [], auth, locale = 'en' }) {
    const me = auth?.user;
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [editingUser, setEditingUser] = useState(null);
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

    const createForm = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });
    const editForm = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });
    const deleteForm = useForm({ confirm: '' });

    const text = {
        en: {
            usersTitle: 'Users',
            usersDescription: 'Accounts allowed to sign in to the admin panel.',
            addUser: 'Add user',
            colName: 'Name',
            colEmail: 'Email',
            colCreated: 'Created',
            colActions: 'Actions',
            empty: 'No users yet.',
            modalTitle: 'Create user',
            editModalTitle: 'Edit user',
            fieldName: 'Full name',
            fieldEmail: 'Email',
            fieldPassword: 'Password',
            fieldPasswordConfirm: 'Confirm password',
            cancel: 'Cancel',
            save: 'Create user',
            update: 'Save changes',
            deleting: 'Deleting...',
            deleteUser: 'Delete user',
            deleteConfirmTitle: 'Confirm deletion',
            deleteConfirmHint: 'Type DELETE to confirm account removal.',
            deleteConfirmLabel: 'Confirmation',
            deleteConfirmPlaceholder: 'DELETE',
            cannotDeleteSelf: 'You cannot delete your own account here.',
        },
        ru: {
            usersTitle: 'Пользователи',
            usersDescription: 'Аккаунты, которым разрешен вход в админ-панель.',
            addUser: 'Добавить пользователя',
            colName: 'Имя',
            colEmail: 'Email',
            colCreated: 'Создан',
            colActions: 'Действия',
            empty: 'Пользователей пока нет.',
            modalTitle: 'Создать пользователя',
            editModalTitle: 'Редактировать пользователя',
            fieldName: 'Полное имя',
            fieldEmail: 'Email',
            fieldPassword: 'Пароль',
            fieldPasswordConfirm: 'Подтверждение пароля',
            cancel: 'Отмена',
            save: 'Создать пользователя',
            update: 'Сохранить изменения',
            deleting: 'Удаляем...',
            deleteUser: 'Удалить пользователя',
            deleteConfirmTitle: 'Подтверждение удаления',
            deleteConfirmHint: 'Введите DELETE для подтверждения удаления аккаунта.',
            deleteConfirmLabel: 'Подтверждение',
            deleteConfirmPlaceholder: 'DELETE',
            cannotDeleteSelf: 'Нельзя удалить собственный аккаунт из этого окна.',
        },
        uk: {
            usersTitle: 'Користувачі',
            usersDescription: 'Акаунти, яким дозволено вхід до адмін-панелі.',
            addUser: 'Додати користувача',
            colName: "Ім'я",
            colEmail: 'Email',
            colCreated: 'Створено',
            colActions: 'Дії',
            empty: 'Користувачів поки що немає.',
            modalTitle: 'Створити користувача',
            editModalTitle: 'Редагувати користувача',
            fieldName: "Повне ім'я",
            fieldEmail: 'Email',
            fieldPassword: 'Пароль',
            fieldPasswordConfirm: 'Підтвердження пароля',
            cancel: 'Скасувати',
            save: 'Створити користувача',
            update: 'Зберегти зміни',
            deleting: 'Видаляємо...',
            deleteUser: 'Видалити користувача',
            deleteConfirmTitle: 'Підтвердження видалення',
            deleteConfirmHint: 'Введіть DELETE для підтвердження видалення акаунта.',
            deleteConfirmLabel: 'Підтвердження',
            deleteConfirmPlaceholder: 'DELETE',
            cannotDeleteSelf: 'Не можна видалити власний акаунт з цього вікна.',
        },
    };
    const t = text[locale] ?? text.en;

    const formatCreated = (iso) => {
        if (!iso) return '—';
        try {
            return new Date(iso).toLocaleString();
        } catch {
            return iso;
        }
    };

    const openEditModal = (user) => {
        setEditingUser(user);
        setShowDeleteConfirm(false);
        editForm.setData({
            name: user.name ?? '',
            email: user.email ?? '',
            password: '',
            password_confirmation: '',
        });
        editForm.clearErrors();
        deleteForm.reset();
        deleteForm.clearErrors();
    };

    const closeEditModal = () => {
        setEditingUser(null);
        setShowDeleteConfirm(false);
        editForm.reset();
        editForm.clearErrors();
        deleteForm.reset();
        deleteForm.clearErrors();
    };

    return (
        <div className="app-widget p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="admin-section-title">{t.usersTitle}</h2>
                    <p className="mt-1 text-sm text-slate-600">{t.usersDescription}</p>
                </div>
                <button
                    type="button"
                    onClick={() => setShowCreateModal(true)}
                    className="admin-btn-primary"
                >
                    <Plus className="h-4 w-4" />
                    {t.addUser}
                </button>
            </div>

            <div className="admin-table-wrap mt-4">
                <table className="min-w-full text-sm">
                    <thead className="text-xs uppercase tracking-wider">
                        <tr>
                            <th className="px-4 py-3 text-left font-semibold">{t.colName}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.colEmail}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.colCreated}</th>
                            <th className="px-4 py-3 text-left font-semibold">{t.colActions}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 text-slate-700">
                        {users.length === 0 ? (
                            <tr>
                                <td colSpan={4} className="px-4 py-6 text-center text-sm text-slate-400">
                                    {t.empty}
                                </td>
                            </tr>
                        ) : (
                            users.map((u) => (
                                <tr key={u.id} className="transition hover:bg-[var(--admin-surface-container-low)]">
                                    <td className="px-4 py-3 font-medium text-slate-900">{u.name}</td>
                                    <td className="px-4 py-3">{u.email}</td>
                                    <td className="px-4 py-3 text-slate-500">{formatCreated(u.created_at)}</td>
                                    <td className="px-4 py-3">
                                        <button
                                            type="button"
                                            onClick={() => openEditModal(u)}
                                            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-300 px-3 text-xs font-medium text-slate-700 transition hover:bg-slate-100"
                                        >
                                            <Pencil className="h-3.5 w-3.5" />
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {showCreateModal && (
                <Modal title={t.modalTitle} onClose={() => { setShowCreateModal(false); createForm.reset(); createForm.clearErrors(); }}>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('settings.users.store'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    createForm.reset();
                                    setShowCreateModal(false);
                                },
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field form={createForm} field="name" label={t.fieldName} />
                            <Field form={createForm} field="email" label={t.fieldEmail} type="email" />
                            <Field form={createForm} field="password" label={t.fieldPassword} type="password" />
                            <Field form={createForm} field="password_confirmation" label={t.fieldPasswordConfirm} type="password" />
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowCreateModal(false)} className="admin-btn-secondary">
                                {t.cancel}
                            </button>
                            <button type="submit" disabled={createForm.processing} className="admin-btn-primary disabled:opacity-60">
                                <Plus className="h-4 w-4" />
                                {t.save}
                            </button>
                        </div>
                    </form>
                </Modal>
            )}

            {editingUser && (
                <Modal title={t.editModalTitle} onClose={closeEditModal}>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(route('settings.users.update', editingUser.id), {
                                preserveScroll: true,
                                onSuccess: closeEditModal,
                            });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <Field form={editForm} field="name" label={t.fieldName} />
                            <Field form={editForm} field="email" label={t.fieldEmail} type="email" />
                            <Field form={editForm} field="password" label={t.fieldPassword} type="password" />
                            <Field form={editForm} field="password_confirmation" label={t.fieldPasswordConfirm} type="password" />
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={closeEditModal} className="admin-btn-secondary">
                                {t.cancel}
                            </button>
                            <button type="submit" disabled={editForm.processing} className="admin-btn-primary disabled:opacity-60">
                                <Pencil className="h-4 w-4" />
                                {t.update}
                            </button>
                        </div>
                    </form>

                    <div className="mt-5 border-t border-slate-200 pt-4">
                        {Number(editingUser.id) === Number(me?.id) ? (
                            <p className="text-sm text-slate-500">{t.cannotDeleteSelf}</p>
                        ) : !showDeleteConfirm ? (
                            <button
                                type="button"
                                onClick={() => setShowDeleteConfirm(true)}
                                className="inline-flex h-10 items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                            >
                                <Trash2 className="h-4 w-4" />
                                {t.deleteUser}
                            </button>
                        ) : (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    if (deleteForm.data.confirm !== 'DELETE') {
                                        deleteForm.setError('confirm', 'Type DELETE to continue.');
                                        return;
                                    }
                                    deleteForm.delete(route('settings.users.destroy', editingUser.id), {
                                        preserveScroll: true,
                                        onSuccess: closeEditModal,
                                    });
                                }}
                                className="space-y-3"
                            >
                                <div>
                                    <p className="text-sm font-semibold text-red-700">{t.deleteConfirmTitle}</p>
                                    <p className="mt-1 text-sm text-slate-600">{t.deleteConfirmHint}</p>
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-600">{t.deleteConfirmLabel}</label>
                                    <input
                                        type="text"
                                        value={deleteForm.data.confirm}
                                        onChange={(e) => deleteForm.setData('confirm', e.target.value)}
                                        placeholder={t.deleteConfirmPlaceholder}
                                        className="block h-11 w-full rounded-lg border border-red-200 px-3 text-sm shadow-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                                    />
                                    <ErrorText message={deleteForm.errors.confirm} />
                                    <ErrorText message={deleteForm.errors.user_delete} />
                                </div>
                                <div className="flex justify-end gap-2">
                                    <button type="button" onClick={() => { setShowDeleteConfirm(false); deleteForm.reset(); deleteForm.clearErrors(); }} className="admin-btn-secondary">
                                        {t.cancel}
                                    </button>
                                    <button type="submit" disabled={deleteForm.processing} className="inline-flex h-10 items-center gap-2 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white transition hover:bg-red-700 disabled:opacity-60">
                                        <Trash2 className="h-4 w-4" />
                                        {deleteForm.processing ? t.deleting : t.deleteUser}
                                    </button>
                                </div>
                            </form>
                        )}
                    </div>
                </Modal>
            )}
        </div>
    );
}

function Modal({ title, children, onClose }) {
    return (
        <div className="admin-modal-overlay">
            <div className="admin-modal max-w-xl">
                <div className="flex items-start justify-between">
                    <h3 className="admin-section-title">{title}</h3>
                    <button type="button" onClick={onClose} className="inline-flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
                        <X className="h-4 w-4" />
                    </button>
                </div>
                <div className="mt-4">{children}</div>
            </div>
        </div>
    );
}

function Field({ form, field, label, type = 'text' }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-slate-600">{label}</label>
            <input
                type={type}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
                className="admin-input"
            />
            <ErrorText message={form.errors[field]} />
        </div>
    );
}

function ErrorText({ message }) {
    if (!message) return null;
    return <p className="mt-2 text-sm text-red-600">{message}</p>;
}
