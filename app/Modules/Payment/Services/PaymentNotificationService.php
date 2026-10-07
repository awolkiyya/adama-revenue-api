<?php

notifyPaymentReceived(Payment $payment);

notifyPaymentPartiallyPaid(Payment $payment);

notifyPaymentFullyPaid(Payment $payment);

notifyPaymentFailed(Payment $payment);

notifyBankTransferSubmitted(Payment $payment);

notifyBankTransferRejected(Payment $payment);