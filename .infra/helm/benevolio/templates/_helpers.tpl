{{/*
Expand the name of the chart.
*/}}
{{- define "benevolio.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Create a fully qualified app name.
*/}}
{{- define "benevolio.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{/*
Chart name and version label value.
*/}}
{{- define "benevolio.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels.
*/}}
{{- define "benevolio.labels" -}}
helm.sh/chart: {{ include "benevolio.chart" . }}
{{ include "benevolio.selectorLabels" . }}
app.kubernetes.io/version: {{ .Values.image.tag | default .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end }}

{{/*
Selector labels.
*/}}
{{- define "benevolio.selectorLabels" -}}
app.kubernetes.io/name: {{ include "benevolio.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Web component selector labels.
*/}}
{{- define "benevolio.web.selectorLabels" -}}
{{ include "benevolio.selectorLabels" . }}
app.kubernetes.io/component: web
{{- end }}

{{/*
Gotenberg component selector labels.
*/}}
{{- define "benevolio.gotenberg.selectorLabels" -}}
{{ include "benevolio.selectorLabels" . }}
app.kubernetes.io/component: gotenberg
{{- end }}
