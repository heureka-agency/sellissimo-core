export function returnActions(row) {
    const actions = [];
    const add = (key, label, primary = false) => actions.push({ key, label: __(label), primary });
    if (row.actionUrl && row.status === 'requested') add('approve', 'Approve return', true);
    if (row.actionUrl && row.status === 'approved') add('receive', 'Receive return', true);
    if (row.refundUrl && row.refund?.due) add('refund', row.refundableOnline ? 'Issue refund' : 'Record manual refund', true);
    if (row.balanceUrl && row.status === 'received' && row.balance?.difference && ['unsettled', 'expired', 'awaiting_refund'].includes(row.balance.status)) add('balance', 'Settle exchange balance', true);
    if (row.actionUrl && row.status === 'received' && row.replacement && (!row.balance?.difference || row.balance.status === 'applied')) add('dispatch', 'Dispatch exchange', true);
    if (row.actionUrl) {
        if (row.status === 'requested') add('decline', 'Decline return');
        if (['requested', 'approved', 'received'].includes(row.status)) {
            add('shipping', 'Return shipping details');
            if (row.replacement && !row.parcels.length && (!row.exchange_amendment || row.balance?.status === 'expired')) {
                if (row.alternatives.length) add('substitute', 'Change replacement');
                add('convert', 'Switch to refund');
            }
        }
        add('message', 'Message buyer');
    }
    return actions;
}
