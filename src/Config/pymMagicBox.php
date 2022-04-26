<?php

return [
    "default_currency_code" => "EUR", //Valuta di default per i pagamenti (ISO 4217)
	"profiles" => [ //Settaggi e credenziali dei vari engines. Sotto common.{method_name} vanno i settaggi comuni a tutti i merchant, sotto {merchant_id}.{method_name} vanno i settaggi per ogni specifici per un merchant-method. {method_name} è sempre snake case.
		"common" => [], 
	],		
	"min_log_level" => "DEBUG", //Livello minimo di log. Secondo specifiche RFC 5424. Impostare a false per disabilitare il sistema di log.	
	"log_rotation" => "30 days", //Intervallo di cancellazione dei log. E' possibile impostare qualsiasi valore accettabile per la funzione Carbon::sub. Impostare false per disabilitare la concellazione automatica dei log. Intervallo minimo: 1 giorno.
];