<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class tb_master_menu_roles extends Model
{
    protected $table = 'tb_master_menu_roles';
    protected $fillable = ['menu_id', 'role_id', 'role_name'];

    public function menu()
    {
        return $this->belongsTo(tb_master_menus::class, 'menu_id');
    }

    public function role()
    {
        return $this->belongsTo(TbMasterRole::class, 'role_id');
    }
}
