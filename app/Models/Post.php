<?php

namespace App\Models;

use ZoltaGaska\Database\Model;

class Post extends Model
{
    // Zdefiniuj, które pola można masowo przypisywać za pomocą metody `create`
    protected $fillable = ['title', 'content'];
}
