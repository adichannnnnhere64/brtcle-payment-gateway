<?php

namespace Adichan\Payment\Tests\TestModels;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    
    protected $fillable = ['name', 'email'];
}
