<?php

return [
    'settings_test' => ['subject' => 'Test message from your store', 'message' => 'This message was requested from your store’s email settings.', 'action' => 'Open email settings'],
    'voucher' => ['subject' => 'Your gift voucher', 'message' => 'Use this code at checkout to redeem your gift voucher.', 'action' => 'Check voucher balance'],
    'stock_available' => ['subject' => 'Your item is back in stock', 'message' => 'The variant you requested is available again.', 'action' => 'View product'],
    'stock_confirm' => ['subject' => 'Confirm your stock alert', 'message' => 'Confirm to receive one message when this variant is available.', 'action' => 'Confirm alert'],
    'stock_low' => ['subject' => 'Stock is running low', 'message' => 'Available stock crossed the low-stock threshold.', 'action' => 'View inventory'],
    'stock_exhausted' => ['subject' => 'An item sold out', 'message' => 'This variant has no units available to sell.', 'action' => 'View inventory'],
    'order_placed' => ['subject' => 'A new order was placed', 'message' => 'Open the order to review payment and fulfillment.', 'action' => 'View order'],
    'receipt_link' => ['subject' => 'Your new receipt link', 'message' => 'You requested a new link to your order receipt.', 'action' => 'View your receipt'],
    'accepted' => ['subject' => 'Your order was accepted', 'message' => 'Thank you for your order. Payment will be collected on delivery.', 'due' => 'Due on delivery', 'action' => 'View your order'],
    'invitation' => ['subject' => 'Claim your guest orders', 'message' => 'Your store invited you to add guest orders to your account. This invitation expires in 24 hours.', 'action' => 'Review guest orders'],

    'refund' => ['subject' => 'Your order refund', 'message' => 'A refund has been issued for your order.', 'action' => 'View updated receipt'],

    'cancelled' => ['subject' => 'Your order was cancelled', 'message' => 'Your order has been cancelled. You can view its details below.', 'action' => 'View your order'],
    'receipt' => ['subject' => 'Your order receipt', 'message' => 'Thank you for your order.', 'action' => 'View your receipt and downloads'],
    'draft' => ['subject' => 'Your order payment link', 'message' => 'Review your order and complete checkout within seven days.', 'action' => 'Review and pay'],
    'claim' => ['subject' => 'Confirm your Sellissimo order', 'message' => 'Confirm that this order belongs in your account.', 'action' => 'Confirm your order'],
    'shipped' => ['subject' => 'Your parcel has shipped', 'message' => 'Your parcel is on its way.', 'action' => 'View your order and delivery details'],
    'recovery' => ['subject' => 'Your saved shopping cart', 'message' => 'Your cart is ready to review. Prices and availability will be checked again.', 'action' => 'Review your cart'],
    'amendment' => ['subject'=>'Your order has a balance to pay','message'=>'Review the proposed changes to your order and pay the difference.','action'=>'Review order changes'],
    'return_update' => ['subject' => 'An update on your return', 'message' => 'Your store has updated your return request.', 'action' => 'View your return'],
    'return_actions' => ['shipping' => 'Return shipping details updated', 'balance' => 'Exchange balance opened', 'approve' => 'Return approved', 'decline' => 'Return declined', 'receive' => 'Returned items received',
        'dispatch' => 'Replacement dispatched', 'substitute' => 'Replacement changed', 'convert' => 'Exchange changed to refund review', 'message' => 'Message from the store'],
    'tracking' => 'Tracking number',
];
