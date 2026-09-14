import LegalDocument, { LegalSection } from '@/Components/LegalDocument';
import LegalLayout from '@/Layouts/LegalLayout';
import { Head } from '@inertiajs/react';

export default function DataDeletionStatus({ status_label: statusLabel }) {
    return (
        <LegalLayout title="Data deletion request status">
            <Head title="Data deletion request status" />

            <LegalDocument>
                <LegalSection title="Status">
                    <p>{statusLabel}</p>
                </LegalSection>
            </LegalDocument>
        </LegalLayout>
    );
}
