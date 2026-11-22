# The digest is checked against input-lock.json by prepare-context.py.
FROM --platform=linux/amd64 docker.io/library/debian@sha256:7378260c0a098d27ac206225db5e7d92b8c7c84ea7afccc86eeb513756bbd392
COPY debs/ /input/debs/
COPY debs.tsv /input/debs.tsv
RUN --network=none set -eu; \
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 0755 /usr/sbin/policy-rc.d; \
    DEBIAN_FRONTEND=noninteractive apt-get -y --allow-downgrades --no-download --no-install-recommends install /input/debs/*.deb; \
    while read -r package version; do test "$(dpkg-query -W -f='${Version}' "$package")" = "$version"; done < /input/debs.tsv; \
    rm -rf /input /var/lib/apt/lists/* /var/lib/mysql /var/log/mysql /var/log/apt/* /var/log/dpkg.log
COPY fixture/ /opt/shop2/fixture/
COPY wordpress-core/ /opt/shop2/wordpress-core/
COPY wordpress-tests/ /opt/shop2/wordpress-tests/
COPY vendor/ /opt/shop2/fixture/vendor/
COPY source-manifest.json /opt/shop2/source-manifest.json
RUN --network=none set -eu; \
    mkdir -p /work; chown 10001:10001 /work; \
    dpkg-query -W -f='${Package}\t${Version}\t${Architecture}\n' > /opt/shop2/dpkg-installed.tsv
ENV HOME=/work TMPDIR=/work WP_TESTS_DIR=/opt/shop2/wordpress-tests
USER 10001:10001
WORKDIR /opt/shop2/fixture
ENTRYPOINT ["/bin/bash","/opt/shop2/fixture/entrypoint.sh"]
