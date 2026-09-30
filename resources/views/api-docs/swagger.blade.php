<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $config->get('ui.title') ?? config('app.name').' - API Docs' }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
    </style>
</head>
<body>
<div id="swagger-ui"></div>

<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
    window.ui = SwaggerUIBundle({
        ...@json($config->renderer()->all(except: ['view'])),
        spec: @json($result->spec()),
        dom_id: '#swagger-ui',
        deepLinking: true,
        requestInterceptor: (request) => {
            // The API answers JSON regardless, but be explicit like a real client.
            request.headers['Accept'] = 'application/json';
            return request;
        },
    });
</script>
</body>
</html>
