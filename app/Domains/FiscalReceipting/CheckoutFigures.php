<?php

namespace App\Domains\FiscalReceipting;

/** Validates ZRA's per-guest reports and counts the shared room once, in minor units. */
final class CheckoutFigures
{
    /**
     * @param  array<mixed>  $reports
     * @param  array<string, mixed>  $request
     * @return array{room: int, extras: int, tax: int, levy: int, total: int, numbers: list<string>}|null
     */
    public function of(array $reports, array $request): ?array
    {
        if ($reports === [] || ! array_is_list($reports)
            || ! is_array($request['documentNumberList'] ?? null)
            || ! in_array($request['currency'] ?? null, ['USD', 'EURO', 'TZS'], true)
            || count($reports) !== count($request['documentNumberList'])) {
            return null;
        }
        $payloads = app(VfmsPayloads::class);
        $currency = $payloads->fromVfmsCurrency($request['currency']);
        $expected = array_column($request['documentNumberList'], 'documentNumber');
        $documents = $numbers = $rooms = [];
        $extras = $serviceTax = $levy = 0;
        foreach ($reports as $report) {
            if (! is_array($report)
                || ! is_string($report['reportNumber'] ?? null) || trim($report['reportNumber']) === ''
                || ! is_string($report['guest']['documentNo'] ?? null)
                || ! in_array($report['guest']['documentNo'], $expected, true)
                || in_array($report['guest']['documentNo'], $documents, true)
                || in_array($report['reportNumber'], $numbers, true)
                || ($report['accommodation']['currency'] ?? null) !== $request['currency']) {
                return null;
            }
            $documents[] = $report['guest']['documentNo'];
            $numbers[] = $report['reportNumber'];
            foreach (['fullName', 'documentName', 'country'] as $field) {
                if (! is_string($report['guest'][$field] ?? null)) {
                    return null;
                }
            }
            $lines = $report['room']['room'] ?? null;
            if (! is_array($lines) || ! array_is_list($lines) || $lines === []) {
                return null;
            }
            $guestRoom = $guestRoomTax = 0;
            $guestRooms = [];
            foreach ($lines as $line) {
                if (! is_array($line) || ! is_string($line['roomName'] ?? null) || ! is_string($line['dateIn'] ?? null)) {
                    return null;
                }
                foreach (['amount', 'tax', 'days', 'accommodationPrice', 'accommodationDiscount'] as $field) {
                    if (! $this->amount($line[$field] ?? null)) {
                        return null;
                    }
                }
                $amount = $payloads->minor($line['amount'], $currency);
                $tax = $payloads->minor($line['tax'], $currency);
                if ($tax > $amount) {
                    return null;
                }
                // ZRA repeats room lines across guest reports; differing figures for one room period need investigation.
                $key = $line['roomName'].'|'.$line['dateIn'];
                if (isset($guestRooms[$key])) {
                    return null;
                }
                $guestRooms[$key] = true;
                $figures = ['amount' => $amount, 'tax' => $tax, 'days' => $line['days'], 'price' => $line['accommodationPrice'], 'discount' => $line['accommodationDiscount']];
                if (isset($rooms[$key]) && $rooms[$key] != $figures) {
                    return null;
                }
                $rooms[$key] = $figures;
                $guestRoom += $amount;
                $guestRoomTax += $tax;
            }
            $service = $report['service']['subTotal'] ?? null;
            $total = $report['total'] ?? null;
            if (! is_array($service) || ! is_array($total)) {
                return null;
            }
            foreach ([$service, $total] as $part) {
                if (! $this->amount($part['totalAmount'] ?? null) || ! $this->amount($part['totalTax'] ?? null) || $part['totalTax'] > $part['totalAmount']) {
                    return null;
                }
            }
            if (! $this->amount($report['totalInfrastructure'] ?? null)) {
                return null;
            }
            $guestExtras = $payloads->minor($service['totalAmount'], $currency);
            $guestServiceTax = $payloads->minor($service['totalTax'], $currency);
            if ($payloads->minor($total['totalAmount'], $currency) !== $guestRoom + $guestExtras
                || $payloads->minor($total['totalTax'], $currency) !== $guestRoomTax + $guestServiceTax) {
                return null;
            }
            $extras += $guestExtras;
            $serviceTax += $guestServiceTax;
            $levy += $payloads->minor($report['totalInfrastructure'], $currency);
        }
        $room = array_sum(array_column($rooms, 'amount'));
        $tax = array_sum(array_column($rooms, 'tax')) + $serviceTax;
        $total = $room + $extras + $levy;
        if ((float) $total >= PHP_INT_MAX || (float) $tax >= PHP_INT_MAX) {
            return null;
        }

        return ['room' => $room, 'extras' => $extras, 'tax' => $tax, 'levy' => $levy, 'total' => $total, 'numbers' => $numbers];
    }

    private function amount(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value * 100 < PHP_INT_MAX;
    }
}
