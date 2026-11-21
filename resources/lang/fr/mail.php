<?php

return [
    'settings_test' => ['subject' => 'Message de test de votre boutique', 'message' => 'Ce message a été demandé depuis les réglages de votre boutique.', 'action' => 'Ouvrir les réglages des e-mails'],
    'voucher' => ['subject' => 'Votre chèque-cadeau', 'message' => 'Saisissez ce code lors du paiement pour utiliser votre chèque-cadeau.', 'action' => 'Consulter le solde'],
    'stock_available' => ['subject' => 'Votre article est de retour', 'message' => 'La variante demandée est à nouveau disponible.', 'action' => 'Voir le produit'],
    'stock_confirm' => ['subject' => 'Confirmez votre alerte', 'message' => 'Confirmez pour recevoir un message lorsque cette variante sera disponible.', 'action' => 'Confirmer'],
    'stock_low' => ['subject' => 'Le stock est faible', 'message' => 'Le stock disponible est passé sous le seuil prévu.', 'action' => 'Voir le stock'],
    'stock_exhausted' => ['subject' => 'Un article est épuisé', 'message' => 'Cette variante ne dispose plus de stock à vendre.', 'action' => 'Voir le stock'],
    'order_placed' => ['subject' => 'Nouvelle commande', 'message' => 'Ouvrez la commande pour consulter son paiement et sa préparation.', 'action' => 'Voir la commande'],
    'receipt_link' => ['subject' => 'Votre nouveau lien de reçu', 'message' => 'Vous avez demandé un nouveau lien vers le reçu de votre commande.', 'action' => 'Consulter votre reçu'],
    'accepted' => ['subject' => 'Votre commande est confirmée', 'message' => 'Merci pour votre commande. Le paiement sera encaissé à la livraison.', 'due' => 'À régler à la livraison', 'action' => 'Voir votre commande'],
    'invitation' => ['subject' => 'Rattachez vos commandes invité', 'message' => 'Votre boutique vous invite à rattacher vos commandes à votre compte. Cette invitation expire dans 24 heures.', 'action' => 'Consulter les commandes'],

    'refund' => ['subject' => 'Remboursement de votre commande', 'message' => 'Un remboursement a été effectué pour votre commande.', 'action' => 'Voir le reçu mis à jour'],

    'cancelled' => ['subject' => 'Votre commande a été annulée', 'message' => 'Votre commande a été annulée. Consultez ses détails ci-dessous.', 'action' => 'Voir votre commande'],
    'receipt' => ['subject' => 'Votre reçu de commande', 'message' => 'Merci pour votre commande.', 'action' => 'Consulter le reçu et les téléchargements'],
    'draft' => ['subject' => 'Votre lien de paiement', 'message' => 'Vérifiez votre commande et réglez-la dans les sept jours.', 'action' => 'Vérifier et payer'],
    'claim' => ['subject' => 'Confirmez votre commande', 'message' => 'Confirmez que cette commande vous appartient.', 'action' => 'Confirmer la commande'],
    'shipped' => ['subject' => 'Votre colis a été expédié', 'message' => 'Votre colis est en route.', 'action' => 'Suivre votre commande'],
    'recovery' => ['subject' => 'Votre panier enregistré', 'message' => 'Retrouvez votre panier. Les prix et les disponibilités seront vérifiés à nouveau.', 'action' => 'Voir votre panier'],
    'amendment' => ['subject'=>'Solde à régler pour votre commande','message'=>'Vérifiez les modifications proposées et réglez la différence.','action'=>'Vérifier les modifications'],
    'return_update' => ['subject' => 'Suivi de votre retour', 'message' => 'Votre boutique a mis à jour votre demande de retour.', 'action' => 'Consulter votre retour'],
    'return_actions' => ['shipping' => 'Informations de retour mises à jour', 'balance' => 'Solde de l’échange disponible', 'approve' => 'Retour approuvé', 'decline' => 'Retour refusé', 'receive' => 'Articles retournés reçus',
        'dispatch' => 'Remplacement expédié', 'substitute' => 'Article de remplacement modifié', 'convert' => 'Échange converti en demande de remboursement', 'message' => 'Message de la boutique'],
    'tracking' => 'Numéro de suivi',
];
