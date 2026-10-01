FROM golang:1.26-alpine AS build
WORKDIR /src
COPY . .
RUN printf 'module gw\n\ngo 1.26\n' > go.mod && CGO_ENABLED=0 go build -o /out/gateway . && cd loadgen && printf 'module lg\n\ngo 1.26\n' > go.mod && CGO_ENABLED=0 go build -o /out/loadgen .

FROM scratch
COPY --from=build /out/ /
