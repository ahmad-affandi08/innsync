/** What the ledger writes for itself in English (`Payment by bank transfer`, `Refund by cash`, a folio called `Guest`), shown in the person's language. Nothing stored changes. */
export type FolioTextLabels = {
    guest: string;
    paymentBy: (method: string) => string;
    refundBy: (method: string) => string;
};

const PAYMENT = /^Payment by ([a-z_ ]+)$/;
const REFUND = /^Refund by ([a-z_ ]+)$/;

export function folioLabel(label: string, labels: FolioTextLabels): string {
    return label === 'Guest' ? labels.guest : label;
}

/** Only the text the system wrote is translated; what a person typed (a charge description) is left as it is. */
export function postingText(description: string, labels: FolioTextLabels): string {
    const paid = PAYMENT.exec(description);

    if (paid !== null) return labels.paymentBy((paid[1] ?? '').replace(/ /g, '_'));

    const refunded = REFUND.exec(description);

    return refunded === null ? description : labels.refundBy((refunded[1] ?? '').replace(/ /g, '_'));
}
