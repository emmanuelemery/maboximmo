<?php
declare(strict_types=1);

if (!function_exists('rh_sepa_escape')) {
    function rh_sepa_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}

if (!function_exists('rh_generate_sepa_xml')) {
    /**
     * Generate a basic SEPA pain.001.001.02 XML string.
     *
     * $debtor: ['name','iban','bic']
     * $transfers: [['name','iban','bic','amount','remittance']]
     */
    function rh_generate_sepa_xml(array $debtor, array $transfers, array $opts = []): string
    {
        $msgId = $opts['message_id'] ?? ('MBI-' . date('Ymd-His'));
        $pmtInfId = $opts['payment_id'] ?? ('PMT-' . date('Ymd'));
        $execDate = $opts['execution_date'] ?? date('Y-m-d');
        $batchBooking = isset($opts['batch_booking']) ? (bool)$opts['batch_booking'] : true;
        $currency = $opts['currency'] ?? 'EUR';
        $name = (string)($debtor['name'] ?? '');
        $iban = strtoupper(str_replace(' ', '', (string)($debtor['iban'] ?? '')));
        $bic = strtoupper(str_replace(' ', '', (string)($debtor['bic'] ?? '')));

        $nbTxs = count($transfers);
        $ctrlSum = 0.0;
        foreach ($transfers as $t) {
            $ctrlSum += (float)$t['amount'];
        }
        $ctrlSumStr = number_format($ctrlSum, 2, '.', '');

        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $xml[] = '  <CstmrCdtTrfInitn>';
        $xml[] = '    <GrpHdr>';
        $xml[] = '      <MsgId>' . rh_sepa_escape($msgId) . '</MsgId>';
        $xml[] = '      <CreDtTm>' . date('Y-m-d\TH:i:s') . '</CreDtTm>';
        $xml[] = '      <NbOfTxs>' . $nbTxs . '</NbOfTxs>';
        $xml[] = '      <CtrlSum>' . $ctrlSumStr . '</CtrlSum>';
        $xml[] = '      <InitgPty><Nm>' . rh_sepa_escape($name) . '</Nm></InitgPty>';
        $xml[] = '    </GrpHdr>';
        $xml[] = '    <PmtInf>';
        $xml[] = '      <PmtInfId>' . rh_sepa_escape($pmtInfId) . '</PmtInfId>';
        $xml[] = '      <PmtMtd>TRF</PmtMtd>';
        $xml[] = '      <BtchBookg>' . ($batchBooking ? 'true' : 'false') . '</BtchBookg>';
        $xml[] = '      <NbOfTxs>' . $nbTxs . '</NbOfTxs>';
        $xml[] = '      <CtrlSum>' . $ctrlSumStr . '</CtrlSum>';
        $xml[] = '      <PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf>';
        $xml[] = '      <ReqdExctnDt>' . rh_sepa_escape($execDate) . '</ReqdExctnDt>';
        $xml[] = '      <Dbtr><Nm>' . rh_sepa_escape($name) . '</Nm></Dbtr>';
        $xml[] = '      <DbtrAcct><Id><IBAN>' . rh_sepa_escape($iban) . '</IBAN></Id></DbtrAcct>';
        if ($bic !== '') {
            $xml[] = '      <DbtrAgt><FinInstnId><BIC>' . rh_sepa_escape($bic) . '</BIC></FinInstnId></DbtrAgt>';
        }
        $xml[] = '      <ChrgBr>SLEV</ChrgBr>';

        $i = 1;
        foreach ($transfers as $t) {
            $amt = number_format((float)$t['amount'], 2, '.', '');
            $credName = (string)$t['name'];
            $credIban = strtoupper(str_replace(' ', '', (string)$t['iban']));
            $credBic = strtoupper(str_replace(' ', '', (string)($t['bic'] ?? '')));
            $remit = (string)($t['remittance'] ?? 'Salaire');

            $xml[] = '      <CdtTrfTxInf>';
            $xml[] = '        <PmtId><EndToEndId>' . rh_sepa_escape($msgId . '-' . $i) . '</EndToEndId></PmtId>';
            $xml[] = '        <Amt><InstdAmt Ccy="' . $currency . '">' . $amt . '</InstdAmt></Amt>';
            $xml[] = '        <CdtrAgt><FinInstnId><BIC>' . rh_sepa_escape($credBic !== '' ? $credBic : 'NOTPROVIDED') . '</BIC></FinInstnId></CdtrAgt>';
            $xml[] = '        <Cdtr><Nm>' . rh_sepa_escape($credName) . '</Nm></Cdtr>';
            $xml[] = '        <CdtrAcct><Id><IBAN>' . rh_sepa_escape($credIban) . '</IBAN></Id></CdtrAcct>';
            $xml[] = '        <RmtInf><Ustrd>' . rh_sepa_escape($remit) . '</Ustrd></RmtInf>';
            $xml[] = '      </CdtTrfTxInf>';
            $i++;
        }

        $xml[] = '    </PmtInf>';
        $xml[] = '  </CstmrCdtTrfInitn>';
        $xml[] = '</Document>';
        return implode("\n", $xml);
    }
}

?>