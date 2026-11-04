import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { EmptyState } from '@/components/ui/empty-state';

export default function Receipts() {
    return (
        <>
            <Head title="Receipts" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    title="Receipts"
                    description="Issued documents for the selected property."
                />
                <EmptyState
                    title="No receipts"
                    description="Issued receipts and guest check-out reports will appear here."
                />
            </div>
        </>
    );
}
