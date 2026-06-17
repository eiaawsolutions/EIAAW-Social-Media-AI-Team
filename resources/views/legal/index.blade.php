@extends('layouts.eiaaw')

@section('title', 'Legal — EIAAW Social Media Team')
@section('description', 'All legal documents for EIAAW Social Media Team: Terms of Service, Acceptable Use Policy, AI Content Disclaimer, Privacy Policy, and Data Processing Addendum.')

@section('content')
<x-legal-shell
  eyebrow="Legal"
  heading="Legal <em>documents</em>"
  intro="Everything that governs your use of EIAAW Social Media Team, in one place. By subscribing you agree to these documents; we ask you to confirm your acceptance in the app."
>
  <p class="meta">EIAAW SOLUTIONS · SSM Reg. No. 202603133419 (CT0164540-H) · Governed by the laws of Malaysia</p>

  <table>
    <thead>
      <tr><th>Document</th><th>Last updated</th></tr>
    </thead>
    <tbody>
      @foreach (config('legal.documents') as $doc)
        <tr>
          <td><a href="{{ route($doc['route']) }}">{{ $doc['name'] }}</a></td>
          <td>{{ $doc['updated'] }}</td>
        </tr>
      @endforeach
      <tr>
        <td><a href="{{ route('legal.security') }}">Security</a></td>
        <td>&mdash;</td>
      </tr>
    </tbody>
  </table>

  <p>
    Questions about any of these can be sent to
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
  </p>
</x-legal-shell>
@endsection
