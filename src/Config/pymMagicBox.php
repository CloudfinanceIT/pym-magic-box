<?php 
return [
	"profiles" => [ //Settaggi e credenziali dei vari engines. Sotto common.{method_name} vanno i settaggi comuni a tutti i merchant, sotto {merchant_id}.{method_name} vanno i settaggi per ogni specifici per un merchant-method. {method_name} è sempre snake case.
		"common" => [], 
	];	
	"bb_code" => [
		"len" => 5, //Lunghezza dei codici di verifica degli ordini (ex bb_code CF3). Min 3, Max 16
		"only_manual" => true //I codici di verifica degli ordini devono essere generati solo per le metodologie di pagamento manuali?
	],	
	"unique_payments" => true, //Se è true il sistema impedisce il pagamento, la conferma o il rimborso ripetuti dello stesso ordine (fa fede il campo order_ref su pmb_payments)
	"min_log_level" => "DEBUG", //Livello minimo di log. Secondo specifiche RFC 5424. Impostare a false per disabilitare il sistema di log.	
	"log_rotation" => "30 days", //Intervallo di cancellazione dei log. E' possibile impostare qualsiasi valore accettabile per la funzione Carbon::sub. Impostare false per disabilitare la concellazione automatica dei log. Intervallo minimo: 1 giorno.
]
?>