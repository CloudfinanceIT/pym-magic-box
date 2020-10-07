<?php 
return [
	"methods" => [], //Settaggi dei vari metodi di pagamento comuni a tutti merchant. Ogni chiave di questo array deve essere il nome di un metodo di pagamento (da tabell pmb_methods) in formato snake case
	"bb_code" => [
		"len" => 5, //Lunghezza dei codici di verifica degli ordini (ex bb_code CF3). Min 3, Max 16
		"only_manual" => true //I codici di verifica degli ordini devono essere generati solo per le metodologie di pagamento manuali?
	],	
	"unique_payments" => true, //Se è true il sistema impedisce il pagamento, la conferma o il rimborso ripetuti dello stesso ordine (fa fede il campo order_ref su pmb_payments)
	"min_log_level" => "DEBUG", //Livello minimo di log. Secondo specifiche RFC 5424. Impostare a false per disabilitare il sistema di log.	
	"log_rotation" => "30 days", //Intervallo di cancellazione dei log. E' possibile impostare qualsiasi valore accettabile per la funzione Carbon::sub. Impostare false per disabilitare la concellazione automatica dei log. Intervallo minimo: 1 giorno.
]
?>