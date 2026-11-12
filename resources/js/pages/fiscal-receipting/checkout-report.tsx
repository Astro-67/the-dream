import CheckoutReport from '@/features/fiscal-receipting/zra-documents';
import { index, show as showReservation } from '@/routes/reservations';
import { index as receipts } from '@/routes/receipts';

const layout = ({
    stay,
    reservationUrl,
}: {
    stay: { reservation_number: string };
    reservationUrl: string | null;
}) => ({
    breadcrumbs: [
        ...(reservationUrl
            ? [
                  { title: 'Reservations', href: index() },
                  {
                      title: stay.reservation_number,
                      href: showReservation(stay.reservation_number),
                  },
              ]
            : [{ title: 'Receipts', href: receipts() }]),
        { title: 'ZRA documents', href: '#' },
    ],
});

export default Object.assign(CheckoutReport, { layout });
