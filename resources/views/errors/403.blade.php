@extends('errors.layout')

@section('code', '403')
@section('image', '401.svg')
@section('title', __('Access denied'))
@section('message', $exception->getMessage() ?: __('You don\'t have permission to open this page. If you think you should, ask your school administrator.'))
