<?php
namespace App\Models;
use ZoltaGaska\Database\Model;

class Post extends Model
{
    protected array $fillable = ['title', 'content'];
    protected array $columns = ['created_at'];
}
