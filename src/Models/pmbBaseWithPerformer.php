<?php

namespace Mantonio84\pymMagicBox\Models;


abstract class pmbBaseWithPerformer extends pmbBase {
        
        public function performer(){
		return $this->belongsTo(pmbPerformer::class);
	}
    
        public function scopeOfPerformers($query, $v){
            if (is_array($v)){
                return $query->whereIn("performer_id",array_map("intval",$v));
            }else if (is_int($v) || ctype_digit($v)){
                return $query->where("performer_id",intval($v));
            }else if ($v instanceof pmbPerformer){
                return $query->where("performer_id",$v->getKey());
            }
            return $query;
	}
        
        public function scopeMerchant($query, string $merchant_id){
            return $query->whereHas("performer", function ($q) use ($merchant_id){
               return $q->where("merchant_id",$merchant_id)->enabled();
            });
        }
}
